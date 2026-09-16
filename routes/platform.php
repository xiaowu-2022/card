<?php

use App\Http\Controllers\Admin\AdminLocaleController;
use App\Http\Controllers\Platform\AccountOperationsController;
use App\Http\Controllers\Platform\AdministratorController;
use App\Http\Controllers\Platform\AssetsController;
use App\Http\Controllers\Platform\CardOperationsController;
use App\Http\Controllers\Platform\CardProductController;
use App\Http\Controllers\Platform\CardProviderController;
use App\Http\Controllers\Platform\CompanyConfiguration\OnboardingController;
use App\Http\Controllers\Platform\CompanyConfiguration\PromotionController;
use App\Http\Controllers\Platform\CompanyConfiguration\TeamController;
use App\Http\Controllers\Platform\CompanyConfiguration\TenantArticleController;
use App\Http\Controllers\Platform\CompanyConfiguration\TenantEmailSettingsController;
use App\Http\Controllers\Platform\CompanyConfiguration\TenantSettingsController;
use App\Http\Controllers\Platform\CompanyConfiguration\TenantSmsSettingsController;
use App\Http\Controllers\Platform\DashboardController;
use App\Http\Controllers\Platform\DomainManagementController;
use App\Http\Controllers\Platform\FinancialOperationsController;
use App\Http\Controllers\Platform\KycSettingsController;
use App\Http\Controllers\Platform\NotificationProfilesController;
use App\Http\Controllers\Platform\PlatformAuthController;
use App\Http\Controllers\Platform\PlatformDomainController;
use App\Http\Controllers\Platform\TenantInvitationController;
use App\Http\Controllers\Platform\TenantLifecycleController;
use App\Http\Controllers\Platform\TenantManagementController;
use App\Http\Controllers\Platform\TopupVerificationController;
use App\Http\Controllers\Platform\TronWithdrawalsController;
use App\Http\Controllers\Platform\UserOperationsController;
use App\Http\Middleware\PlatformCompanyConfiguration;
use Illuminate\Support\Facades\Route;

Route::prefix('platform')->name('platform.')->group(function (): void {
    Route::middleware('admin.scope:platform,tenant.manage')->group(function (): void {
        Route::get('/settings/assets', [AssetsController::class, 'settings'])->name('assets.settings');
        Route::post('/settings/assets', [AssetsController::class, 'save'])->middleware('throttle:10,1')->name('assets.settings.save');
        Route::post('/tenants/{tenant}/assets/settings', [AssetsController::class, 'save'])->whereUuid('tenant')->middleware('throttle:10,1')->name('assets.company.save');
    });
    Route::get('/asset-deposits', [AssetsController::class, 'deposits'])->middleware('admin.scope:platform,wallet_topups.read')->name('assets.deposits');
    Route::get('/asset-withdrawals', [AssetsController::class, 'withdrawals'])->middleware('admin.scope:platform,withdrawals.read')->name('assets.withdrawals');
    foreach (['confirm' => 'wallet_topups.confirm', 'recheck' => 'wallet_topups.verify', 'review' => 'withdrawals.review', 'verify' => 'withdrawals.review', 'reveal' => 'withdrawals.review'] as $action => $permission) {
        Route::post('/tenants/{tenant}/asset-orders/{order}/'.$action, [AssetsController::class, $action])->whereUuid(['tenant', 'order'])->middleware(['admin.scope:platform,'.$permission, 'throttle:5,1'])->name('assets.'.$action);
    }
    Route::get('/asset-tron-withdrawals', [TronWithdrawalsController::class, 'index'])->middleware('admin.scope:platform,withdrawals.read')->name('assets.tron.withdrawals');
    foreach (['review', 'verify', 'reveal'] as $action) {
        Route::post('/tenants/{tenant}/asset-tron-withdrawals/{order}/'.$action, [TronWithdrawalsController::class, $action])->whereUuid(['tenant', 'order'])->middleware(['admin.scope:platform,withdrawals.review', 'throttle:5,1'])->name('assets.tron.'.$action);
    }
    Route::post('/locale', AdminLocaleController::class)->middleware('throttle:30,1')->name('locale.update');
    Route::get('/login', [PlatformAuthController::class, 'create'])->name('login');
    Route::post('/login', [PlatformAuthController::class, 'store'])->name('login.store');

    Route::middleware('admin.scope:platform,tenant.read')->group(function (): void {
        Route::get('/', fn () => redirect('/platform/tenants'))->name('home');
        Route::get('/demo', DashboardController::class)->name('dashboard');
        Route::get('/tenants', [TenantManagementController::class, 'index'])->name('tenants.index');
        Route::get('/tenants/{tenant}', [TenantManagementController::class, 'show'])->whereUuid('tenant')->name('tenants.show');
        Route::post('/logout', [PlatformAuthController::class, 'destroy'])->name('logout');
    });

    Route::middleware('admin.scope:platform,card_product.read')->get('/card-products', [CardProductController::class, 'index'])->name('card-products.index');
    Route::middleware('admin.scope:platform,cards.read')->get('/cards', CardOperationsController::class)->name('cards.index');
    Route::middleware(['admin.scope:platform,cards.read', 'throttle:120,1'])->get('/tenants/{tenant}/cards/{card}/transactions', [CardOperationsController::class, 'transactions'])->whereUuid(['tenant', 'card'])->name('cards.transactions');
    Route::middleware(['admin.scope:platform,cards.read', 'throttle:30,1'])->post('/tenants/{tenant}/cards/{card}/refresh', [CardOperationsController::class, 'refresh'])->whereUuid(['tenant', 'card'])->name('cards.refresh');
    Route::middleware('admin.scope:platform,users.read')->get('/users', UserOperationsController::class)->name('users.index');
    Route::middleware('admin.scope:platform,provider_operation.read')->get('/card-providers', CardProviderController::class)->name('card-providers.index');
    Route::middleware(['admin.scope:platform,card_provider_reference.manage', 'throttle:30,1'])->group(function (): void {
        Route::post('/card-providers', [CardProviderController::class, 'store'])->name('card-providers.store');
        Route::put('/card-providers/{reference}', [CardProviderController::class, 'update'])->whereUuid('reference')->name('card-providers.update');
    });
    Route::middleware('admin.scope:platform,kyc.read')->group(function (): void {
        Route::get('/kyc', [AccountOperationsController::class, 'index'])->name('kyc.index');
        Route::get('/tenants/{tenant}/kyc', [AccountOperationsController::class, 'kyc'])->whereUuid('tenant')->name('kyc.company');
    });
    Route::middleware('admin.scope:platform,wallet.read')->group(function (): void {
        Route::get('/wallets', [AccountOperationsController::class, 'index'])->name('wallets.index');
        Route::get('/tenants/{tenant}/wallets', [AccountOperationsController::class, 'wallets'])->whereUuid('tenant')->name('wallets.company');
    });
    Route::middleware('admin.scope:platform,wallet_topups.read')->get('/tenants/{tenant}/topups', [TopupVerificationController::class, 'index'])->whereUuid('tenant')->name('topups.index');
    Route::middleware('admin.scope:platform,wallet_topups.read')->get('/topups', [TopupVerificationController::class, 'all'])->name('topups.all');
    Route::middleware(['admin.scope:platform,wallet_topups.verify', 'throttle:5,1'])->post('/tenants/{tenant}/topups/{topup}/verify', [TopupVerificationController::class, 'verify'])->whereUuid(['tenant', 'topup'])->name('topups.verify');
    Route::middleware(['admin.scope:platform,wallet_topups.confirm', 'throttle:5,1'])->post('/tenants/{tenant}/topups/{topup}/confirm', [TopupVerificationController::class, 'confirm'])->whereUuid(['tenant', 'topup'])->name('topups.confirm');
    Route::middleware('admin.scope:platform,card_product.manage')->group(function (): void {
        Route::post('/card-products', [CardProductController::class, 'store'])->name('card-products.store');
        Route::put('/card-products/{cardProduct}', [CardProductController::class, 'update'])->whereUuid('cardProduct')->name('card-products.update');
    });

    Route::middleware('admin.scope:platform,tenant.manage')->group(function (): void {
        Route::put('/tenants/{tenant}/name', [TenantManagementController::class, 'rename'])->whereUuid('tenant')->name('tenants.rename');
        Route::post('/settings/domains/assign/{tenant}', [DomainManagementController::class, 'assign'])->whereUuid('tenant')->name('settings.domains.assign');
        Route::get('/settings/domains', [PlatformDomainController::class, 'index'])->name('settings.domains');
        Route::post('/settings/domains', [PlatformDomainController::class, 'store'])->name('settings.domains.store');
        Route::post('/settings/domains/{domain}/activate', [PlatformDomainController::class, 'activate'])->whereUuid('domain')->name('settings.domains.activate');
        Route::delete('/settings/domains/{domain}', [PlatformDomainController::class, 'destroy'])->whereUuid('domain')->name('settings.domains.destroy');
        Route::get('/settings/sms', [NotificationProfilesController::class, 'sms'])->name('settings.sms');
        Route::get('/settings/email', [NotificationProfilesController::class, 'email'])->name('settings.email');
        Route::post('/settings/sms/{profile?}', [NotificationProfilesController::class, 'saveSms'])->whereUuid('profile')->middleware('throttle:5,1')->name('settings.sms.save');
        Route::post('/settings/email/{profile?}', [NotificationProfilesController::class, 'saveEmail'])->whereUuid('profile')->middleware('throttle:5,1')->name('settings.email.save');

        Route::get('/settings/kyc', [KycSettingsController::class, 'show'])->name('settings.kyc');
        Route::post('/settings/kyc', [KycSettingsController::class, 'update'])->middleware('throttle:20,1')->name('settings.kyc.update');
        Route::put('/tenants/{tenant}/deposit-settings', [TenantManagementController::class, 'updateDeposit'])->whereUuid('tenant')->name('tenants.deposit-settings.update');
        Route::get('/tenants/{tenant}/domains', [DomainManagementController::class, 'index'])->whereUuid('tenant')->name('domains');
        Route::post('/tenants/{tenant}/domains', [DomainManagementController::class, 'store'])->whereUuid('tenant')->name('domains.store');
        Route::post('/tenants/{tenant}/domains/{domain}/activate', [DomainManagementController::class, 'activate'])->whereUuid(['tenant', 'domain'])->name('domains.activate');
        Route::post('/tenants/{tenant}/domains/{domain}/primary', [DomainManagementController::class, 'primary'])->whereUuid(['tenant', 'domain'])->name('domains.primary');
        Route::delete('/tenants/{tenant}/domains/{domain}', [DomainManagementController::class, 'destroy'])->whereUuid(['tenant', 'domain'])->name('domains.destroy');
        Route::get('/tenants/create', [TenantManagementController::class, 'create'])->name('tenants.create');
        Route::post('/tenants', [TenantManagementController::class, 'store'])->name('tenants.store');
        Route::post('/tenants/{tenant}/suspend', [TenantLifecycleController::class, 'suspend'])->whereUuid('tenant')->name('tenants.suspend');
        Route::post('/tenants/{tenant}/reactivate', [TenantLifecycleController::class, 'reactivate'])->whereUuid('tenant')->name('tenants.reactivate');
    });

    Route::middleware('admin.scope:platform,admin_team.manage')->group(function (): void {
        Route::post('/administrators', [AdministratorController::class, 'store'])->middleware('throttle:5,1')->name('administrators.store');
        Route::post('/tenants/{tenant}/invitations/{invitation}/resend', [TenantInvitationController::class, 'resend'])->whereUuid(['tenant', 'invitation'])->name('invitations.resend');
        Route::post('/tenants/{tenant}/invitations/{invitation}/cancel', [TenantInvitationController::class, 'cancel'])->whereUuid(['tenant', 'invitation'])->name('invitations.cancel');
    });
    Route::middleware('admin.scope:platform,admin_team.read')->get('/administrators', [AdministratorController::class, 'index'])->name('administrators.index');
    Route::middleware('admin.scope:platform,audit.read')->get('/financial-operations', FinancialOperationsController::class)->name('financial-operations.index');
    Route::prefix('/tenants/{tenant}/configuration')->whereUuid('tenant')->middleware(['admin.scope:platform,tenant.manage', PlatformCompanyConfiguration::class])->name('company-configuration.')->group(function (): void {
        Route::get('/domains', [DomainManagementController::class, 'index'])->name('domains');
        Route::post('/domains', [DomainManagementController::class, 'assign'])->name('domains.assign');
        Route::get('/card-products', [App\Http\Controllers\Platform\CompanyConfiguration\CardProductController::class, 'index'])->name('card-products.index');
        Route::put('/card-products/{cardProduct}', [App\Http\Controllers\Platform\CompanyConfiguration\CardProductController::class, 'update'])->whereUuid('cardProduct')->name('card-products.update');
        Route::get('/promotion', [PromotionController::class, 'show'])->name('promotion');
        Route::post('/promotion', [PromotionController::class, 'update'])->middleware('throttle:20,1')->name('promotion.update');
        Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
        Route::get('/settings/{section?}', [TenantSettingsController::class, 'show'])->where('section', 'branding|locales|business|kyc|articles|sms|email')->name('settings');
        Route::post('/settings/email', [TenantEmailSettingsController::class, 'update'])->middleware('throttle:5,1')->name('settings.email');
        Route::post('/settings/email/test', [TenantEmailSettingsController::class, 'test'])->middleware('throttle:5,1')->name('settings.email.test');
        Route::post('/settings/sms', [TenantSmsSettingsController::class, 'update'])->middleware('throttle:5,1')->name('settings.sms');
        Route::post('/settings/articles/{article}/{locale}', [TenantArticleController::class, 'update'])
            ->whereIn('article', ['terms', 'privacy', 'account-closure'])->whereIn('locale', ['zh-CN', 'en', 'ms', 'es'])->name('settings.articles.update');
        Route::post('/settings/branding', [TenantSettingsController::class, 'branding'])->name('settings.branding');
        Route::post('/settings/locales', [TenantSettingsController::class, 'locales'])->name('settings.locales');
        Route::post('/settings/business', [TenantSettingsController::class, 'business'])->name('settings.business');
        Route::post('/settings/kyc', [TenantSettingsController::class, 'kyc'])->name('settings.kyc');
        Route::post('/onboarding/activate', [OnboardingController::class, 'activate'])->name('activate');
        Route::get('/team', [TeamController::class, 'index'])->name('team');
        Route::post('/team/administrators', [TeamController::class, 'store'])->middleware('throttle:5,1')->name('team.create');
        Route::put('/team/memberships/{membership}', [TeamController::class, 'update'])->whereUuid('membership')->middleware('throttle:10,1')->name('team.update');
        Route::post('/team/invitations', [TeamController::class, 'invite'])->name('team.invite');
        Route::post('/team/invitations/{invitation}/resend', [TeamController::class, 'resend'])->whereUuid('invitation')->name('team.resend');
        Route::post('/team/invitations/{invitation}/cancel', [TeamController::class, 'cancel'])->whereUuid('invitation')->name('team.cancel');
    });

});
