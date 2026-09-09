<?php

declare(strict_types=1);

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! extension_loaded('pcntl')) {
    fwrite(STDERR, "pcntl is required for Phase 4 concurrency verification.\n");
    exit(2);
}

Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
$tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
$user = User::query()->where('tenant_id', $tenant->id)->firstOrFail();
$reviewer = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
$imagePath = tempnam(sys_get_temp_dir(), 'ledger-concurrency-image-');
file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
$application = app(SubmitKycApplicationAction::class)->execute($tenant, $user, 'MY', 'CONCURRENCY-WALLET-ID', new UploadedFile($imagePath, 'front.png', 'image/png', null, true), new UploadedFile($imagePath, 'back.png', 'image/png', null, true));
app(ApproveKycAction::class)->execute($tenant->id, $application->id, $reviewer);
unlink($imagePath);

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
            } catch (Throwable) {
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

$activationStatuses = $parallel([
    fn () => app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id, (string) Str::uuid()),
    fn () => app(ActivateUserWalletAction::class)->execute($tenant->id, $user->id, (string) Str::uuid()),
]);
$wallet = Wallet::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->firstOrFail();
$available = LedgerAccount::query()->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserAvailable)->firstOrFail();
$clearing = LedgerAccount::query()->where('tenant_id', $tenant->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing)->firstOrFail();

$plan = fn (string $key, string $availableDelta, string $clearingDelta, bool $reverse = false): LedgerPostingPlan => new LedgerPostingPlan(
    $tenant->id, 'USD', $key, 'CORE_CONCURRENCY_TEST', 'CORE_TEST', (string) Str::uuid(), null,
    $reverse
        ? [new LedgerPostingInstruction($available->id, Money::of($availableDelta, 'USD')), new LedgerPostingInstruction($clearing->id, Money::of($clearingDelta, 'USD'))]
        : [new LedgerPostingInstruction($clearing->id, Money::of($clearingDelta, 'USD')), new LedgerPostingInstruction($available->id, Money::of($availableDelta, 'USD'))],
);

$idempotentPlan = $plan('concurrency:same-event', '1.00000000', '-1.00000000');
$idempotencyStatuses = $parallel([fn () => app(LedgerWriter::class)->post($idempotentPlan), fn () => app(LedgerWriter::class)->post($idempotentPlan)]);
$orderStatuses = $parallel([
    fn () => app(LedgerWriter::class)->post($plan('concurrency:order-a', '1.00000000', '-1.00000000')),
    fn () => app(LedgerWriter::class)->post($plan('concurrency:order-b', '1.00000000', '-1.00000000', true)),
]);
app(LedgerWriter::class)->post($plan('concurrency:negative-fund', '1.00000000', '-1.00000000'));
$balanceBeforeNegativeRace = LedgerAccount::query()->findOrFail($available->id)->balance;
$negativeStatuses = $parallel([
    fn () => app(LedgerWriter::class)->post($plan('concurrency:negative-a', '-'.$balanceBeforeNegativeRace, $balanceBeforeNegativeRace)),
    fn () => app(LedgerWriter::class)->post($plan('concurrency:negative-b', '-'.$balanceBeforeNegativeRace, $balanceBeforeNegativeRace, true)),
]);

$result = [
    'wallet_activation' => ['children' => $activationStatuses, 'wallets' => Wallet::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->count(), 'user_accounts' => LedgerAccount::query()->where('wallet_id', $wallet->id)->count(), 'tenant_accounts' => LedgerAccount::query()->where('tenant_id', $tenant->id)->whereNull('wallet_id')->count()],
    'same_event' => ['children' => $idempotencyStatuses, 'entries' => LedgerEntry::query()->where('event_key', 'concurrency:same-event')->count()],
    'reversed_order' => ['children' => $orderStatuses],
    'negative_race' => ['children' => $negativeStatuses, 'final_available' => LedgerAccount::query()->findOrFail($available->id)->balance],
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

$passed = $activationStatuses === [0, 0]
    && $result['wallet_activation']['wallets'] === 1
    && $result['wallet_activation']['user_accounts'] === 5
    && $result['wallet_activation']['tenant_accounts'] === 4
    && $idempotencyStatuses === [0, 0]
    && $result['same_event']['entries'] === 1
    && $orderStatuses === [0, 0]
    && collect($negativeStatuses)->sort()->values()->all() === [0, 1]
    && $result['negative_race']['final_available'] === '0.00000000';

exit($passed ? 0 : 1);
