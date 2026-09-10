<?php

declare(strict_types=1);

use App\Application\Card\CreateCardIssueAction;
use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\Enums\MockProviderMode;
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
use App\Infrastructure\Providers\Card\MockCardProvider;
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
    fwrite(STDERR, "pcntl is required for Phase 10 concurrency verification.\n");
    exit(2);
}

Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
$tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
$user = User::query()->where('tenant_id', $tenant->id)->firstOrFail();
$owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
$product = CardProduct::query()->where('provider', 'PHOTONPAY')->firstOrFail();
DB::transaction(function () use ($tenant, $owner): void {
    DB::table('tenants')->where('id', $tenant->id)->update(['default_asset' => 'USDT']);
    $tenant->refresh();
    app(UpdateTenantBusinessSettingsAction::class)->execute($tenant, [
        'required_security_deposit_amount' => '10', 'required_security_deposit_asset' => 'USDT',
        'allow_wallet_topup' => true, 'allow_withdrawal' => true,
    ], $owner);
});
$tenant->refresh();
$imagePath = tempnam(sys_get_temp_dir(), 'phase-ten-concurrency-');
file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
$kyc = app(SubmitKycApplicationAction::class)->execute(
    $tenant, $user, 'MY', 'PHASE-TEN-CONCURRENT',
    new UploadedFile($imagePath, 'front.png', 'image/png', null, true),
    new UploadedFile($imagePath, 'back.png', 'image/png', null, true),
);
app(ApproveKycAction::class)->execute($tenant->id, $kyc->id, $owner);
@unlink($imagePath);
$wallet = app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id)->wallet;
$available = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserAvailable)->firstOrFail();
$clearing = LedgerAccount::query()->where('tenant_id', $tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing)->firstOrFail();
app(LedgerWriter::class)->post(new LedgerPostingPlan(
    $tenant->id, 'USDT', 'phase-ten:concurrency-credit', 'TEST_WALLET_CREDIT', null, null, null,
    [new LedgerPostingInstruction($clearing->id, Money::of('-60', 'USDT')), new LedgerPostingInstruction($available->id, Money::of('60', 'USDT'))],
));
app(FundSecurityDepositAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), '10');
$holder = new ProviderCardholder;
$holder->forceFill([
    'tenant_id' => $tenant->id, 'user_id' => $user->id, 'provider' => 'PHOTONPAY',
    'provider_cardholder_id' => 'MOCK-HOLDER-CONCURRENT', 'status' => 'READY',
    'provider_status' => 'normal', 'provider_review_status' => 'approved', 'submitted_at' => now(), 'synced_at' => now(),
])->save();
app()->instance(CardProviderInterface::class, new MockCardProvider(MockProviderMode::Success, 'READY'));

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
                if (in_array($exception->errorCode, ['INSUFFICIENT_AVAILABLE_BALANCE', 'LEDGER_NEGATIVE_BALANCE'], true)) {
                    exit(0);
                }
                fwrite(STDERR, "Unexpected Card issue rejection: {$exception->errorCode}\n");
                exit(1);
            } catch (Throwable $exception) {
                fwrite(STDERR, "Unexpected Card issue failure: {$exception->getMessage()}\n");
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

$sameRequest = (string) Str::uuid();
$same = $parallel([
    fn () => app(CreateCardIssueAction::class)->execute($tenant->id, $user->id, $sameRequest, $product->id, '20.00'),
    fn () => app(CreateCardIssueAction::class)->execute($tenant->id, $user->id, $sameRequest, $product->id, '20.00'),
]);
$competing = $parallel([
    fn () => app(CreateCardIssueAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), $product->id, '20.00'),
    fn () => app(CreateCardIssueAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), $product->id, '20.00'),
]);

$valid = $same === [0, 0]
    && $competing === [0, 0]
    && CardIssueOrder::query()->count() === 2
    && UserCard::query()->count() === 2
    && LedgerEntry::query()->where('event_type', 'CARD_ISSUE_FEE_HOLD')->count() === 2
    && LedgerEntry::query()->where('event_type', 'CARD_INITIAL_LOAD_HOLD')->count() === 2
    && LedgerEntry::query()->where('event_type', 'CARD_ISSUE_FEE_SETTLE')->count() === 2
    && LedgerEntry::query()->where('event_type', 'CARD_INITIAL_LOAD_SETTLE')->count() === 2
    && $available->fresh()->balance === '0.00000000'
    && app(LedgerReconciliationService::class)->mismatches() === [];

echo json_encode([
    'same_request_children' => $same,
    'competing_balance_children' => $competing,
    'orders' => CardIssueOrder::query()->count(),
    'cards' => UserCard::query()->count(),
    'available' => $available->fresh()->balance,
    'ledger_mismatches' => count(app(LedgerReconciliationService::class)->mismatches()),
], JSON_PRETTY_PRINT).PHP_EOL;

exit($valid ? 0 : 1);
