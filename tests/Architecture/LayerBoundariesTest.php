<?php

arch('domain does not depend on HTTP or infrastructure')
    ->expect('App\Domain')
    ->not->toUse(['App\Http', 'App\Infrastructure']);

arch('KYC domain does not depend on Card')
    ->expect('App\Domain\Kyc')
    ->not->toUse('App\Domain\Card');

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
