<?php

arch('domain does not depend on HTTP or infrastructure')
    ->expect('App\Domain')
    ->not->toUse(['App\Http', 'App\Infrastructure']);

arch('KYC domain does not depend on Card')
    ->expect('App\Domain\Kyc')
    ->not->toUse([
        'App\Domain\Card',
        'App\Domain\CardProvider',
        'App\Domain\Wallet',
        'App\Domain\Ledger',
        'App\Domain\SecurityDeposit',
    ]);

arch('KYC OCR infrastructure does not depend on Wallet Ledger or Card')
    ->expect('App\Infrastructure\Providers\Kyc')
    ->not->toUse([
        'App\Domain\Card',
        'App\Domain\CardProvider',
        'App\Domain\Wallet',
        'App\Domain\Ledger',
        'App\Domain\SecurityDeposit',
    ]);

arch('Ledger core does not depend on business domains')
    ->expect('App\Domain\Ledger')
    ->not->toUse([
        'App\Domain\Kyc',
        'App\Domain\Wallet',
        'App\Domain\Card',
        'App\Domain\CardProvider',
        'App\Domain\SecurityDeposit',
        'App\Domain\Payment',
        'App\Domain\Withdrawal',
        'App\Domain\Commission',
        'App\Domain\Agent',
        'App\Domain\PhotonPay',
    ]);

arch('Wallet domain does not depend on KYC persistence or future financial domains')
    ->expect('App\Domain\Wallet')
    ->not->toUse([
        'App\Domain\Kyc',
        'App\Domain\Payment',
        'App\Domain\Withdrawal',
        'App\Domain\SecurityDeposit',
        'App\Domain\Card',
        'App\Domain\CardProvider',
    ]);

it('keeps OCR providers away from review state and KYC approval away from financial side effects', function (): void {
    $providerSources = collect(glob(app_path('Infrastructure/Providers/Kyc/*.php')))
        ->map(fn (string $path): string => file_get_contents($path))->implode("\n");
    $approval = file_get_contents(app_path('Application/Kyc/ApproveKycAction.php'));

    expect($providerSources)->not->toContain('review_status', 'KycReviewStatus')
        ->and($approval)->not->toContain('Wallet', 'Ledger', 'Balance', 'Deposit', 'Card');
});

arch('User domain remains independent from future business domains')
    ->expect('App\Domain\User')
    ->not->toUse([
        'App\Domain\Kyc',
        'App\Domain\Wallet',
        'App\Domain\Ledger',
        'App\Domain\SecurityDeposit',
        'App\Domain\Card',
        'App\Domain\CardProduct',
        'App\Domain\CardProvider',
    ]);

arch('provider adapters do not depend on money or deposit domains')
    ->expect('App\Infrastructure\Providers')
    ->not->toUse([
        'App\Domain\Wallet',
        'App\Domain\Ledger',
        'App\Domain\SecurityDeposit',
    ]);

arch('Payment provider adapters do not mutate Wallet or Ledger')
    ->expect('App\Infrastructure\Providers\Payment')
    ->not->toUse([
        'App\Domain\Wallet',
        'App\Domain\Ledger\Models',
        'App\Domain\Ledger\Services\LedgerWriter',
        'App\Domain\CardProvider',
    ]);

arch('Payment domain remains independent from Ledger and Card Provider')
    ->expect('App\Domain\Payment')
    ->not->toUse(['App\Domain\Ledger', 'App\Domain\CardProvider']);

it('keeps webhook controllers away from ledger settlement', function (): void {
    $source = file_get_contents(app_path('Http/Controllers/Webhooks/PaymentWebhookController.php'));
    expect($source)->not->toContain('LedgerWriter', 'LedgerPosting', 'CreditWalletTopupAction');
});

arch('controllers do not access ledger persistence')
    ->expect('App\Http\Controllers')
    ->not->toUse('App\Infrastructure\Persistence');

arch('controllers remain final and use controller suffix')
    ->expect([
        'App\Http\Controllers\Public\LandingController',
        'App\Http\Controllers\User\DashboardController',
        'App\Http\Controllers\User\WalletController',
        'App\Http\Controllers\User\DemoWalletController',
        'App\Http\Controllers\User\CardsController',
        'App\Http\Controllers\TenantAdmin\DashboardController',
        'App\Http\Controllers\TenantAdmin\UsersController',
        'App\Http\Controllers\TenantAdmin\SettingsController',
        'App\Http\Controllers\TenantAdmin\TenantAdminAuthController',
        'App\Http\Controllers\TenantAdmin\InvitationAcceptanceController',
        'App\Http\Controllers\TenantAdmin\OnboardingController',
        'App\Http\Controllers\TenantAdmin\TenantSettingsController',
        'App\Http\Controllers\TenantAdmin\DomainManagementController',
        'App\Http\Controllers\TenantAdmin\TeamController',
        'App\Http\Controllers\TenantAdmin\AdminRecentAuthenticationController',
        'App\Http\Controllers\TenantAdmin\KycController',
        'App\Http\Controllers\TenantAdmin\KycDocumentController',
        'App\Http\Controllers\TenantAdmin\UserWalletController',
        'App\Http\Controllers\User\KycController',
        'App\Http\Controllers\Platform\DashboardController',
        'App\Http\Controllers\Platform\TenantsController',
        'App\Http\Controllers\Platform\PlatformAuthController',
        'App\Http\Controllers\Platform\TenantManagementController',
        'App\Http\Controllers\Platform\TenantLifecycleController',
        'App\Http\Controllers\Platform\TenantInvitationController',
    ])
    ->classes()
    ->toBeFinal()
    ->toHaveSuffix('Controller');

it('keeps direct ledger persistence writes inside the ledger core', function (): void {
    $businessSources = collect(glob(app_path('Application/**/*.php')))
        ->reject(fn (string $path): bool => str_contains($path, '/Application/Wallet/'))
        ->map(fn (string $path): string => file_get_contents($path))->implode("\n");
    $controllerSources = collect(glob(app_path('Http/Controllers/**/*.php')))
        ->map(fn (string $path): string => file_get_contents($path))->implode("\n");
    $kycApproval = file_get_contents(app_path('Application/Kyc/ApproveKycAction.php'));

    expect($businessSources)->not->toContain('LedgerPosting::create', "table('ledger_postings')", 'table("ledger_postings")')
        ->and($controllerSources)->not->toContain('LedgerPosting', 'LedgerWriter', 'LedgerPostingPlan', 'ledger_accounts')
        ->and($kycApproval)->not->toContain('WalletProvisioner', 'ActivateUserWalletAction', 'LedgerWriter');
});

it('keeps ledger account locks and external IO out of application callers', function (): void {
    $applicationSources = collect(glob(app_path('Application/**/*.php')))
        ->map(fn (string $path): string => file_get_contents($path));
    $ledgerSources = collect(glob(app_path('Domain/Ledger/**/*.php')))
        ->map(fn (string $path): string => file_get_contents($path))->implode("\n");

    expect($applicationSources->contains(fn (string $source): bool => preg_match('/LedgerAccount::query\(\)[^;]*lockForUpdate/s', $source) === 1))->toBeFalse()
        ->and($ledgerSources)->not->toContain('Http::', 'Mail::', 'Storage::', 'PhotonPay');
});
