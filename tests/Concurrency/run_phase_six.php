<?php

declare(strict_types=1);

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\SecurityDeposit\FundSecurityDepositAction;
use App\Application\Tenant\UpdateTenantBusinessSettingsAction;
use App\Application\Wallet\ActivateUserWalletAction;
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
    fwrite(STDERR, "pcntl is required for Phase 6 concurrency verification.\n");
    exit(2);
}

Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
$tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
$user = User::query()->where('tenant_id', $tenant->id)->firstOrFail();
$owner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
app(UpdateTenantBusinessSettingsAction::class)->execute($tenant, [
    'required_security_deposit_amount' => '50', 'required_security_deposit_asset' => 'USD', 'allow_wallet_topup' => true, 'allow_withdrawal' => false,
], $owner);
$imagePath = tempnam(sys_get_temp_dir(), 'deposit-concurrency-image-');
file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
$application = app(SubmitKycApplicationAction::class)->execute(
    $tenant, $user, 'MY', 'PHASE-SIX-CONCURRENCY',
    new UploadedFile($imagePath, 'front.png', 'image/png', null, true),
    new UploadedFile($imagePath, 'back.png', 'image/png', null, true),
);
app(ApproveKycAction::class)->execute($tenant->id, $application->id, $owner);
@unlink($imagePath);
$wallet = app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id)->wallet;
$available = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserAvailable->value)->firstOrFail();
$deposit = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserSecurityDeposit->value)->firstOrFail();
$hold = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserWithdrawalHold->value)->firstOrFail();
$clearing = LedgerAccount::query()->where('tenant_id', $tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing->value)->firstOrFail();
app(LedgerWriter::class)->post(new LedgerPostingPlan(
    $tenant->id, 'USD', 'phase-six:initial-credit', 'TEST_WALLET_CREDIT', null, null, null,
    [new LedgerPostingInstruction($clearing->id, Money::of('-100', 'USD')), new LedgerPostingInstruction($available->id, Money::of('100', 'USD'))],
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
                if (in_array($exception->errorCode, ['SECURITY_DEPOSIT_ALREADY_SATISFIED', 'SECURITY_DEPOSIT_AMOUNT_CHANGED', 'INSUFFICIENT_AVAILABLE_BALANCE', 'LEDGER_NEGATIVE_BALANCE'], true)) {
                    exit(0);
                }
                fwrite(STDERR, "Unexpected Phase 6 rejection: {$exception->errorCode}\n");
                exit(1);
            } catch (Throwable $exception) {
                fwrite(STDERR, "Unexpected Phase 6 failure: {$exception->getMessage()}\n");
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

$differentRequests = $parallel([
    fn () => app(FundSecurityDepositAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), '50'),
    fn () => app(FundSecurityDepositAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), '50'),
]);

app(UpdateTenantBusinessSettingsAction::class)->execute($tenant, [
    'required_security_deposit_amount' => '75', 'required_security_deposit_asset' => 'USD', 'allow_wallet_topup' => true, 'allow_withdrawal' => false,
], $owner);
$competingBalance = $parallel([
    fn () => app(FundSecurityDepositAction::class)->execute($tenant->id, $user->id, (string) Str::uuid(), '25'),
    fn () => app(LedgerWriter::class)->post(new LedgerPostingPlan(
        $tenant->id, 'USD', 'phase-six:competing-debit', 'TEST_WALLET_DEBIT', null, null, null,
        [new LedgerPostingInstruction($available->id, Money::of('-30', 'USD')), new LedgerPostingInstruction($hold->id, Money::of('30', 'USD'))],
    )),
]);

$availableBalance = $available->fresh()->balance;
$depositBalance = $deposit->fresh()->balance;
$validRace = $differentRequests === [0, 0]
    && $competingBalance === [0, 0]
    && in_array($depositBalance, ['50.00000000', '75.00000000'], true)
    && Money::of($availableBalance, 'USD')->compare(Money::of('0', 'USD')) >= 0
    && LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_FUND')->count() <= 2
    && app(LedgerReconciliationService::class)->mismatches() === [];

echo json_encode([
    'different_request_children' => $differentRequests,
    'competing_balance_children' => $competingBalance,
    'available' => $availableBalance,
    'deposit' => $depositBalance,
    'funding_entries' => LedgerEntry::query()->where('event_type', 'SECURITY_DEPOSIT_FUND')->count(),
    'ledger_mismatches' => count(app(LedgerReconciliationService::class)->mismatches()),
], JSON_PRETTY_PRINT).PHP_EOL;

exit($validRace ? 0 : 1);
