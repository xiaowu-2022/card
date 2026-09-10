<?php

declare(strict_types=1);

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Payment\CreateTrc20WalletTopupAction;
use App\Application\Payment\ExpireTrc20TopupsAction;
use App\Application\Payment\ProcessIncomingTrc20TransferAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerReconciliationService;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Withdrawal\DTOs\IncomingBlockchainTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! extension_loaded('pcntl') || ! app()->environment(['local', 'testing'])) {
    fwrite(STDERR, "Phase 8 concurrency verification requires pcntl in local/testing.\n");
    exit(2);
}

config([
    'payment.trc20_deposit_address' => 'T111111111111111111111111111111111',
    'payment.trc20_token_contract' => 'T222222222222222222222222222222222',
    'payment.trc20_required_confirmations' => 20,
]);
Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);

/** @param list<Closure():void> $tasks @return list<int> */
$parallel = function (array $tasks): array {
    $children = [];
    foreach ($tasks as $task) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::disconnect();
            try {
                DB::reconnect();
                $task();
                exit(0);
            } catch (Throwable $exception) {
                fwrite(STDERR, 'Phase 8 child failed: '.$exception::class.' '.$exception->getMessage()."\n");
                exit(1);
            }
        }
        $children[] = $pid;
    }
    $statuses = [];
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $statuses[] = pcntl_wexitstatus($status);
    }
    DB::disconnect();
    DB::reconnect();

    return $statuses;
};

$imagePath = tempnam(sys_get_temp_dir(), 'phase-eight-concurrency-');
file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
$prepare = function (Tenant $tenant, User $user, string $identity) use ($imagePath): void {
    DB::transaction(function () use ($tenant): void {
        $tenant->update(['default_asset' => 'USDT']);
        $tenant->businessSettings()->update(['required_security_deposit_asset' => 'USDT', 'allow_wallet_topup' => true]);
    });
    $owner = AdminUser::query()->where('email', 'owner@'.($tenant->slug === 'tenant-a' ? 'a' : 'b').'.localhost')->firstOrFail();
    $application = app(SubmitKycApplicationAction::class)->execute(
        $tenant->fresh(), $user, 'MY', $identity,
        new UploadedFile($imagePath, "{$identity}-front.png", 'image/png', null, true),
        new UploadedFile($imagePath, "{$identity}-back.png", 'image/png', null, true),
    );
    app(ApproveKycAction::class)->execute($tenant->id, $application->id, $owner);
    app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id);
};

$tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
$userA1 = User::query()->where('tenant_id', $tenantA->id)->firstOrFail();
$prepare($tenantA, $userA1, 'PHASE-EIGHT-A1');
$userA2 = User::query()->create([
    'tenant_id' => $tenantA->id, 'email' => 'second@a.localhost', 'password_hash' => Hash::make('local-password'),
    'status' => UserStatus::Active, 'email_verified_at' => now(),
]);
$prepare($tenantA->fresh(), $userA2, 'PHASE-EIGHT-A2');
$tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
$userB = User::query()->where('tenant_id', $tenantB->id)->firstOrFail();
$prepare($tenantB, $userB, 'PHASE-EIGHT-B');
@unlink($imagePath);

$create = fn (string $tenantId, string $userId) => app(CreateTrc20WalletTopupAction::class)
    ->execute($tenantId, $userId, '100', (string) Str::uuid());
$sameTenant = $parallel([
    fn () => $create($tenantA->id, $userA1->id),
    fn () => $create($tenantA->id, $userA2->id),
]);
$sameTenantAmounts = WalletTopupOrder::query()->where('tenant_id', $tenantA->id)->pluck('expected_amount');

DB::table('wallet_topup_orders')->update(['status' => WalletTopupStatus::Cancelled->value, 'cancelled_at' => now(), 'updated_at' => now()]);
$crossTenant = $parallel([
    fn () => $create($tenantA->id, $userA1->id),
    fn () => $create($tenantB->id, $userB->id),
]);
$crossTenantAmounts = WalletTopupOrder::query()->where('status', WalletTopupStatus::Pending->value)->pluck('expected_amount');

DB::table('wallet_topup_orders')->where('status', WalletTopupStatus::Pending->value)
    ->update(['status' => WalletTopupStatus::Cancelled->value, 'cancelled_at' => now(), 'updated_at' => now()]);
$unknownOrder = $create($tenantA->id, $userA1->id)->order;
DB::table('wallet_topup_orders')->where('id', $unknownOrder->id)
    ->update(['status' => WalletTopupStatus::Unknown->value, 'updated_at' => now()]);
$reviewOrder = $create($tenantA->id, $userA1->id)->order;
DB::table('wallet_topup_orders')->where('id', $reviewOrder->id)
    ->update(['status' => WalletTopupStatus::RequiresReview->value, 'updated_at' => now()]);
$unresolvedReservedAmounts = collect([$unknownOrder->expected_amount, $reviewOrder->expected_amount]);
$unresolvedReservationAllocation = $parallel([
    fn () => $create($tenantA->id, $userA1->id),
    fn () => $create($tenantB->id, $userB->id),
]);
$unresolvedConcurrentAmounts = WalletTopupOrder::query()->where('status', WalletTopupStatus::Pending->value)->pluck('expected_amount');

DB::table('wallet_topup_orders')->whereIn('status', [
    WalletTopupStatus::Pending->value,
    WalletTopupStatus::Unknown->value,
    WalletTopupStatus::RequiresReview->value,
])->update(['status' => WalletTopupStatus::Cancelled->value, 'cancelled_at' => now(), 'updated_at' => now()]);
$scanOrder = $create($tenantA->id, $userA1->id)->order;
$scanTransfer = new IncomingBlockchainTransfer(
    'TRON', hash('sha256', 'phase-eight-concurrent-scan'), 0, $scanOrder->token_contract,
    $scanOrder->deposit_address, $scanOrder->expected_amount, 20, new DateTimeImmutable,
);
$duplicateScan = $parallel([
    fn () => app(ProcessIncomingTrc20TransferAction::class)->execute($scanTransfer),
    fn () => app(ProcessIncomingTrc20TransferAction::class)->execute($scanTransfer),
]);

CarbonImmutable::setTestNow('2026-09-10 14:00:00');
$expiryOrder = $create($tenantA->id, $userA1->id)->order;
$expiryTransfer = new IncomingBlockchainTransfer(
    'TRON', hash('sha256', 'phase-eight-expiry-race'), 0, $expiryOrder->token_contract,
    $expiryOrder->deposit_address, $expiryOrder->expected_amount, 20, new DateTimeImmutable('2026-09-10 14:29:00'),
);
CarbonImmutable::setTestNow('2026-09-10 14:31:00');
$expiryVsDetection = $parallel([
    fn () => app(ExpireTrc20TopupsAction::class)->execute(),
    fn () => app(ProcessIncomingTrc20TransferAction::class)->execute($expiryTransfer),
]);
CarbonImmutable::setTestNow();

$scanEntries = LedgerEntry::query()->where('event_key', "wallet_topup:{$scanOrder->id}:credit")->count();
$expiryEntries = LedgerEntry::query()->where('event_key', "wallet_topup:{$expiryOrder->id}:credit")->count();
$valid = $sameTenant === [0, 0] && $crossTenant === [0, 0] && $unresolvedReservationAllocation === [0, 0]
    && $duplicateScan === [0, 0] && $expiryVsDetection === [0, 0]
    && $sameTenantAmounts->unique()->count() === 2 && $crossTenantAmounts->unique()->count() === 2
    && $unresolvedConcurrentAmounts->unique()->count() === 2
    && $unresolvedConcurrentAmounts->intersect($unresolvedReservedAmounts)->isEmpty()
    && $scanOrder->fresh()->status === WalletTopupStatus::Credited && $scanEntries === 1
    && $expiryOrder->fresh()->status === WalletTopupStatus::Credited && $expiryEntries === 1
    && app(LedgerReconciliationService::class)->mismatches() === [];

echo json_encode([
    'same_tenant_allocation_children' => $sameTenant,
    'same_tenant_unique_amounts' => $sameTenantAmounts->unique()->count(),
    'cross_tenant_allocation_children' => $crossTenant,
    'cross_tenant_unique_amounts' => $crossTenantAmounts->unique()->count(),
    'unresolved_reservation_children' => $unresolvedReservationAllocation,
    'unresolved_reservation_unique_amounts' => $unresolvedConcurrentAmounts->unique()->count(),
    'unresolved_reservation_bypasses' => $unresolvedConcurrentAmounts->intersect($unresolvedReservedAmounts)->count(),
    'duplicate_scan_children' => $duplicateScan,
    'duplicate_scan_ledger_entries' => $scanEntries,
    'expiry_vs_detection_children' => $expiryVsDetection,
    'expiry_race_status' => $expiryOrder->fresh()->status->value,
    'expiry_race_ledger_entries' => $expiryEntries,
    'ledger_mismatches' => count(app(LedgerReconciliationService::class)->mismatches()),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

exit($valid ? 0 : 1);
