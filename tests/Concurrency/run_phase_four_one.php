<?php

declare(strict_types=1);

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Tenant\SuspendTenantAction;
use App\Application\User\SuspendUserAction;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Services\LedgerReconciliationService;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Errors\DomainException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! extension_loaded('pcntl')) {
    fwrite(STDERR, "pcntl is required for Phase 4.1 concurrency verification.\n");
    exit(2);
}

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
                $category = $exception instanceof DomainException ? $exception->errorCode : $exception::class;
                fwrite(STDERR, "Phase 4.1 child rejected: {$category}\n");
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

$createUser = function (Tenant $tenant, string $label): User {
    return User::query()->create([
        'tenant_id' => $tenant->id,
        'email' => strtolower($label).'@'.$tenant->slug.'.example.test',
        'phone' => null,
        'password_hash' => Hash::make('concurrency-test-only'),
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
    ]);
};

$submit = function (Tenant $tenant, User $user, string $identity): KycApplication {
    $path = tempnam(sys_get_temp_dir(), 'ledger-v41-image-');
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
    try {
        return app(SubmitKycApplicationAction::class)->execute(
            $tenant,
            $user,
            'MY',
            $identity,
            new UploadedFile($path, 'front.png', 'image/png', null, true),
            new UploadedFile($path, 'back.png', 'image/png', null, true),
        );
    } finally {
        @unlink($path);
    }
};
$approve = function (Tenant $tenant, User $user, AdminUser $reviewer, string $identity) use ($submit): void {
    $application = $submit($tenant, $user, $identity);
    app(ApproveKycAction::class)->execute($tenant->id, $application->id, $reviewer);
};

$tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
$tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
$ownerA = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
$ownerB = AdminUser::query()->where('email', 'owner@b.localhost')->firstOrFail();
$platformOwner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();

$userA = User::query()->where('tenant_id', $tenantA->id)->firstOrFail();
$approve($tenantA, $userA, $ownerA, 'V41-A-PRIMARY');
$walletActivation = $parallel([
    fn () => app(ActivateUserWalletAction::class)->execute($tenantA->id, $userA->id),
    fn () => app(ActivateUserWalletAction::class)->execute($tenantA->id, $userA->id),
]);
$walletA = Wallet::query()->where('tenant_id', $tenantA->id)->where('user_id', $userA->id)->firstOrFail();
$availableA = LedgerAccount::query()->where('wallet_id', $walletA->id)->where('account_type', LedgerAccountType::UserAvailable)->firstOrFail();
$clearingA = LedgerAccount::query()->where('tenant_id', $tenantA->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing)->firstOrFail();
$reference = (string) Str::uuid();
$planA = fn (string $key, string $amount, bool $reverse = false): LedgerPostingPlan => new LedgerPostingPlan(
    $tenantA->id, 'USD', $key, 'CORE_CONCURRENCY_TEST', 'CORE_TEST', $reference, null,
    $reverse
        ? [new LedgerPostingInstruction($availableA->id, Money::of($amount, 'USD')), new LedgerPostingInstruction($clearingA->id, Money::of('-'.$amount, 'USD'))]
        : [new LedgerPostingInstruction($clearingA->id, Money::of('-'.$amount, 'USD')), new LedgerPostingInstruction($availableA->id, Money::of($amount, 'USD'))],
);

$samePlan = $planA('v41:same-event', '1.00000000');
$sameEvent = $parallel([fn () => app(LedgerWriter::class)->post($samePlan), fn () => app(LedgerWriter::class)->post($samePlan)]);
$differentEvent = $parallel([
    fn () => app(LedgerWriter::class)->post($planA('v41:different-plan', '10.00000000')),
    fn () => app(LedgerWriter::class)->post($planA('v41:different-plan', '11.00000000')),
]);
$reversedOrder = $parallel([
    fn () => app(LedgerWriter::class)->post($planA('v41:order-a', '1.00000000')),
    fn () => app(LedgerWriter::class)->post($planA('v41:order-b', '1.00000000', true)),
]);

$userB1 = User::query()->where('tenant_id', $tenantB->id)->firstOrFail();
$userB2 = $createUser($tenantB, 'v41-b-second');
$approve($tenantB, $userB1, $ownerB, 'V41-B-FIRST');
$approve($tenantB, $userB2, $ownerB, 'V41-B-SECOND');
$firstTenantWallets = $parallel([
    fn () => app(ActivateUserWalletAction::class)->execute($tenantB->id, $userB1->id),
    fn () => app(ActivateUserWalletAction::class)->execute($tenantB->id, $userB2->id),
]);
$firstTenantWalletCounts = [
    'wallets' => Wallet::query()->where('tenant_id', $tenantB->id)->whereIn('user_id', [$userB1->id, $userB2->id])->count(),
    'user_accounts' => LedgerAccount::query()->whereIn('wallet_id', Wallet::query()->where('tenant_id', $tenantB->id)->whereIn('user_id', [$userB1->id, $userB2->id])->pluck('id'))->count(),
    'tenant_accounts' => LedgerAccount::query()->where('tenant_id', $tenantB->id)->whereNull('wallet_id')->count(),
];
$walletB1 = Wallet::query()->where('tenant_id', $tenantB->id)->where('user_id', $userB1->id)->firstOrFail();
$availableB = LedgerAccount::query()->where('wallet_id', $walletB1->id)->where('account_type', LedgerAccountType::UserAvailable)->firstOrFail();
$clearingB = LedgerAccount::query()->where('tenant_id', $tenantB->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantTopupClearing)->firstOrFail();
$withdrawalB = LedgerAccount::query()->where('tenant_id', $tenantB->id)->whereNull('wallet_id')->where('account_type', LedgerAccountType::TenantWithdrawalClearing)->firstOrFail();
$planB = fn (string $key, string $availableDelta, string $counterDelta): LedgerPostingPlan => new LedgerPostingPlan(
    $tenantB->id, 'USD', $key, 'CORE_CONCURRENCY_TEST', 'CORE_TEST', (string) Str::uuid(), null,
    [new LedgerPostingInstruction($availableB->id, Money::of($availableDelta, 'USD')), new LedgerPostingInstruction($withdrawalB->id, Money::of($counterDelta, 'USD'))],
);
app(LedgerWriter::class)->post(new LedgerPostingPlan(
    $tenantB->id, 'USD', 'v41:negative-fund', 'CORE_CONCURRENCY_TEST', 'CORE_TEST', (string) Str::uuid(), null,
    [new LedgerPostingInstruction($clearingB->id, Money::of('-100', 'USD')), new LedgerPostingInstruction($availableB->id, Money::of('100', 'USD'))],
));
$negativeRace = $parallel([
    fn () => app(LedgerWriter::class)->post($planB('v41:negative-a', '-80', '80')),
    fn () => app(LedgerWriter::class)->post($planB('v41:negative-b', '-80', '80')),
]);
$negativeFinalBalance = LedgerAccount::query()->findOrFail($availableB->id)->balance;
$accountClosureRace = $parallel([
    fn () => app(LedgerWriter::class)->post($planB('v41:account-close-race', '-1', '1')),
    function () use ($availableB): void {
        DB::transaction(function () use ($availableB): void {
            LedgerAccount::query()->whereKey($availableB->id)->lockForUpdate()->firstOrFail();
            DB::table('ledger_accounts')->where('id', $availableB->id)->update(['status' => 'CLOSED', 'updated_at' => now()]);
        });
    },
]);

$userSuspendCandidate = $createUser($tenantA, 'v41-user-suspend');
$approve($tenantA, $userSuspendCandidate, $ownerA, 'V41-USER-SUSPEND');
$activateVsUserSuspend = $parallel([
    fn () => app(ActivateUserWalletAction::class)->execute($tenantA->id, $userSuspendCandidate->id),
    fn () => app(SuspendUserAction::class)->execute($tenantA->id, $userSuspendCandidate->id, $ownerA),
]);

$kycRaceUser = $createUser($tenantA, 'v41-kyc-race');
$kycRaceApplication = $submit($tenantA, $kycRaceUser, 'V41-KYC-RACE');
$readyFile = sys_get_temp_dir().'/v41-kyc-ready-'.Str::uuid();
$kycPid = pcntl_fork();
if ($kycPid === 0) {
    DB::disconnect();
    try {
        DB::reconnect();
        DB::beginTransaction();
        app(ApproveKycAction::class)->execute($tenantA->id, $kycRaceApplication->id, $ownerA);
        touch($readyFile);
        usleep(500_000);
        DB::commit();
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Phase 4.1 KYC child rejected: '.$exception::class."\n");
        DB::rollBack();
        exit(1);
    }
}
$deadline = microtime(true) + 10;
while (! file_exists($readyFile) && microtime(true) < $deadline) {
    usleep(10_000);
}
$kycActivationStartedAt = microtime(true);
$activationAfterKycCommit = false;
if (file_exists($readyFile)) {
    app(ActivateUserWalletAction::class)->execute($tenantA->id, $kycRaceUser->id);
    $activationAfterKycCommit = true;
}
$kycActivationWait = microtime(true) - $kycActivationStartedAt;
pcntl_waitpid($kycPid, $kycStatus);
@unlink($readyFile);
DB::disconnect();
DB::reconnect();
$kycApprovalChild = pcntl_wexitstatus($kycStatus);

$tenantSuspendCandidate = $createUser($tenantB, 'v41-tenant-suspend');
$approve($tenantB, $tenantSuspendCandidate, $ownerB, 'V41-TENANT-SUSPEND');
$activateVsTenantSuspend = $parallel([
    fn () => app(ActivateUserWalletAction::class)->execute($tenantB->id, $tenantSuspendCandidate->id),
    fn () => app(SuspendTenantAction::class)->execute($tenantB->id, $platformOwner),
]);

$result = [
    'same_event_same_plan' => ['children' => $sameEvent, 'entries' => LedgerEntry::query()->where('tenant_id', $tenantA->id)->where('event_key', 'v41:same-event')->count()],
    'same_event_different_plan' => ['children' => $differentEvent, 'entries' => LedgerEntry::query()->where('tenant_id', $tenantA->id)->where('event_key', 'v41:different-plan')->count()],
    'negative_balance_race' => ['children' => $negativeRace, 'final_available' => $negativeFinalBalance],
    'account_closure_race' => [
        'children' => $accountClosureRace,
        'final_status' => LedgerAccount::query()->findOrFail($availableB->id)->status->value,
        'final_available' => LedgerAccount::query()->findOrFail($availableB->id)->balance,
    ],
    'reversed_account_order' => ['children' => $reversedOrder],
    'wallet_activation_race' => ['children' => $walletActivation, 'wallets' => Wallet::query()->where('tenant_id', $tenantA->id)->where('user_id', $userA->id)->count()],
    'two_users_first_activation' => [
        'children' => $firstTenantWallets,
        ...$firstTenantWalletCounts,
    ],
    'activate_vs_user_suspend' => [
        'children' => $activateVsUserSuspend,
        'user_status' => User::query()->findOrFail($userSuspendCandidate->id)->status->value,
        'wallets' => Wallet::query()->where('user_id', $userSuspendCandidate->id)->count(),
    ],
    'kyc_uncommitted_visibility' => [
        'approval_child' => $kycApprovalChild,
        'activation_waited_for_commit' => $kycActivationWait >= 0.25,
        'activation_after_commit' => $activationAfterKycCommit,
    ],
    'activate_vs_tenant_suspend' => [
        'children' => $activateVsTenantSuspend,
        'tenant_status' => Tenant::query()->findOrFail($tenantB->id)->status->value,
        'wallets' => Wallet::query()->where('user_id', $tenantSuspendCandidate->id)->count(),
    ],
    'reconciliation_mismatches' => count(app(LedgerReconciliationService::class)->mismatches()),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

$validRace = fn (array $statuses): bool => in_array($statuses, [[0, 0], [0, 1], [1, 0]], true);
$passed = $sameEvent === [0, 0]
    && $result['same_event_same_plan']['entries'] === 1
    && collect($differentEvent)->sort()->values()->all() === [0, 1]
    && $result['same_event_different_plan']['entries'] === 1
    && collect($negativeRace)->sort()->values()->all() === [0, 1]
    && $result['negative_balance_race']['final_available'] === '20.00000000'
    && $validRace($accountClosureRace)
    && $result['account_closure_race']['final_status'] === 'CLOSED'
    && in_array($result['account_closure_race']['final_available'], ['19.00000000', '20.00000000'], true)
    && $reversedOrder === [0, 0]
    && $walletActivation === [0, 0]
    && $result['wallet_activation_race']['wallets'] === 1
    && $firstTenantWallets === [0, 0]
    && $result['two_users_first_activation']['wallets'] === 2
    && $result['two_users_first_activation']['user_accounts'] === 10
    && $result['two_users_first_activation']['tenant_accounts'] === 4
    && $validRace($activateVsUserSuspend)
    && $result['activate_vs_user_suspend']['user_status'] === 'SUSPENDED'
    && in_array($result['activate_vs_user_suspend']['wallets'], [0, 1], true)
    && $kycApprovalChild === 0
    && $kycActivationWait >= 0.25
    && $activationAfterKycCommit
    && $validRace($activateVsTenantSuspend)
    && $result['activate_vs_tenant_suspend']['tenant_status'] === 'SUSPENDED'
    && in_array($result['activate_vs_tenant_suspend']['wallets'], [0, 1], true)
    && $result['reconciliation_mismatches'] === 0;

exit($passed ? 0 : 1);
