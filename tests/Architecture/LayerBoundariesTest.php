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

arch('controllers do not access ledger persistence')
    ->expect('App\Http\Controllers')
    ->not->toUse('App\Infrastructure\Persistence');

arch('controllers remain final and use controller suffix')
    ->expect([
        'App\Http\Controllers\Public\LandingController',
        'App\Http\Controllers\User\DashboardController',
        'App\Http\Controllers\User\WalletController',
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
