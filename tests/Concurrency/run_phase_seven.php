<?php

declare(strict_types=1);

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Application\Withdrawal\ApproveWithdrawalAction;
use App\Application\Withdrawal\CancelWithdrawalAction;
use App\Application\Withdrawal\CreateWithdrawalAction;
use App\Application\Withdrawal\CreateWithdrawalDestinationAction;
use App\Application\Withdrawal\VerifyWithdrawalTransactionAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerReconciliationService;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Withdrawal\Enums\WithdrawalStatus;
use App\Domain\Withdrawal\Models\WithdrawalOrder;
use App\Domain\Withdrawal\Models\WithdrawalTransactionAttempt;
use App\Support\Errors\DomainException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! extension_loaded('pcntl')) {
    fwrite(STDERR, "pcntl is required for Phase 7 concurrency verification.\n");
    exit(2);
}
if (! app()->environment(['local', 'testing'])) {
    fwrite(STDERR, "Phase 7 concurrency verification is forbidden outside local/testing.\n");
    exit(2);
}

Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
$tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
$user = User::query()->where('tenant_id', $tenant->id)->firstOrFail();
$owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
DB::transaction(function () use ($tenant): void {
    $tenant->update(['default_asset' => 'USDT']);
    app(UpdateTenantBusinessSettingsAction::class)->execute($tenant, [
        'required_security_deposit_amount' => '0', 'required_security_deposit_asset' => 'USDT',
        'allow_wallet_topup' => true, 'allow_withdrawal' => true,
    ], AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());
});
$image = tempnam(sys_get_temp_dir(), 'phase-seven-concurrency-');
file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
$application = app(SubmitKycApplicationAction::class)->execute(
    $tenant, $user, 'MY', 'PHASE-SEVEN-CONCURRENCY',
    new UploadedFile($image, 'front.png', 'image/png', null, true),
    new UploadedFile($image, 'back.png', 'image/png', null, true),
);
app(ApproveKycAction::class)->execute($tenant->id, $application->id, $owner);
@unlink($image);
$wallet = app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id)->wallet;
$destination = app(CreateWithdrawalDestinationAction::class)->execute($tenant->id, $user->id, 'T'.str_repeat('A', 33), 'Concurrency');
$available = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserAvailable->value)->firstOrFail();
$hold = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserWithdrawalHold->value)->firstOrFail();
$topupClearing = LedgerAccount::query()->where('tenant_id', $tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing->value)->firstOrFail();
app(LedgerWriter::class)->post(new LedgerPostingPlan(
    $tenant->id, 'USDT', 'phase-seven:initial-credit', 'TEST_WALLET_CREDIT', null, null, null,
    [new LedgerPostingInstruction($topupClearing->id, Money::of('-100', 'USDT')), new LedgerPostingInstruction($available->id, Money::of('100', 'USDT'))],
));

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
            } catch (DomainException $exception) {
                if (in_array($exception->errorCode, [
                    'INSUFFICIENT_AVAILABLE_BALANCE', 'LEDGER_NEGATIVE_BALANCE', 'WITHDRAWAL_CANNOT_CANCEL',
                    'WITHDRAWAL_NOT_PENDING', 'WITHDRAWAL_TX_HASH_ALREADY_USED',
                ], true)) {
                    exit(0);
                }
                fwrite(STDERR, "Unexpected Phase 7 rejection: {$exception->errorCode}\n");
                exit(1);
            } catch (Throwable $exception) {
                fwrite(STDERR, "Unexpected Phase 7 failure: {$exception->getMessage()}\n");
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

$competingBalance = $parallel([
    fn () => app(CreateWithdrawalAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), $destination->id, '80'),
    fn () => app(CreateWithdrawalAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), $destination->id, '80'),
]);
$raceOrder = WithdrawalOrder::query()->where('status', WithdrawalStatus::Pending->value)->firstOrFail();
$approveVsCancel = $parallel([
    fn () => app(ApproveWithdrawalAction::class)->execute($tenant->id, $raceOrder->id, $owner),
    fn () => app(CancelWithdrawalAction::class)->execute($tenant->id, $user->id, $raceOrder->id),
]);

app(LedgerWriter::class)->post(new LedgerPostingPlan(
    $tenant->id, 'USDT', 'phase-seven:second-credit', 'TEST_WALLET_CREDIT', null, null, null,
    [new LedgerPostingInstruction($topupClearing->id, Money::of('-200', 'USDT')), new LedgerPostingInstruction($available->id, Money::of('200', 'USDT'))],
));
$duplicateOrder = app(CreateWithdrawalAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), $destination->id, '40');
app(ApproveWithdrawalAction::class)->execute($tenant->id, $duplicateOrder->id, $owner);
$duplicateHash = str_repeat('b', 64);
$duplicateVerification = $parallel([
    fn () => app(VerifyWithdrawalTransactionAction::class)->execute($tenant->id, $duplicateOrder->id, $owner, $duplicateHash),
    fn () => app(VerifyWithdrawalTransactionAction::class)->execute($tenant->id, $duplicateOrder->id, $owner, $duplicateHash),
]);

$firstTxOrder = app(CreateWithdrawalAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), $destination->id, '30');
$secondTxOrder = app(CreateWithdrawalAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), $destination->id, '30');
app(ApproveWithdrawalAction::class)->execute($tenant->id, $firstTxOrder->id, $owner);
app(ApproveWithdrawalAction::class)->execute($tenant->id, $secondTxOrder->id, $owner);
$sharedHash = str_repeat('c', 64);
$sameHashOrders = $parallel([
    fn () => app(VerifyWithdrawalTransactionAction::class)->execute($tenant->id, $firstTxOrder->id, $owner, $sharedHash),
    fn () => app(VerifyWithdrawalTransactionAction::class)->execute($tenant->id, $secondTxOrder->id, $owner, $sharedHash),
]);

$raceOrder->refresh();
$sharedSucceeded = WithdrawalOrder::query()->whereIn('id', [$firstTxOrder->id, $secondTxOrder->id])->where('status', WithdrawalStatus::Succeeded->value)->count();
$valid = $competingBalance === [0, 0]
    && $approveVsCancel === [0, 0]
    && $duplicateVerification === [0, 0]
    && $sameHashOrders === [0, 0]
    && WithdrawalOrder::query()->where('amount', '80')->count() === 1
    && in_array($raceOrder->status, [WithdrawalStatus::Approved, WithdrawalStatus::Cancelled], true)
    && LedgerEntry::query()->where('event_key', "withdrawal:{$duplicateOrder->id}:settle")->count() === 1
    && WithdrawalTransactionAttempt::query()->where('tx_hash', $sharedHash)->count() === 1
    && $sharedSucceeded === 1
    && app(LedgerReconciliationService::class)->mismatches() === [];

echo json_encode([
    'competing_balance_children' => $competingBalance,
    'approve_vs_cancel_children' => $approveVsCancel,
    'duplicate_verification_children' => $duplicateVerification,
    'same_hash_two_orders_children' => $sameHashOrders,
    'race_order_status' => $raceOrder->status->value,
    'duplicate_settlement_entries' => LedgerEntry::query()->where('event_key', "withdrawal:{$duplicateOrder->id}:settle")->count(),
    'shared_hash_attempts' => WithdrawalTransactionAttempt::query()->where('tx_hash', $sharedHash)->count(),
    'shared_hash_succeeded_orders' => $sharedSucceeded,
    'ledger_mismatches' => count(app(LedgerReconciliationService::class)->mismatches()),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

exit($valid ? 0 : 1);
