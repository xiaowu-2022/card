<?php

use App\Http\Controllers\Admin\AdminLocaleController;
use App\Http\Controllers\TenantAdmin\AdminRecentAuthenticationController;
use App\Http\Controllers\TenantAdmin\CardOperationsController;
use App\Http\Controllers\TenantAdmin\CardProductController;
use App\Http\Controllers\TenantAdmin\DashboardController;
use App\Http\Controllers\TenantAdmin\InvitationAcceptanceController;
use App\Http\Controllers\TenantAdmin\KycController;
use App\Http\Controllers\TenantAdmin\KycDocumentController;
use App\Http\Controllers\TenantAdmin\OnboardingController;
use App\Http\Controllers\TenantAdmin\PromotionController;
use App\Http\Controllers\TenantAdmin\SupportController;
use App\Http\Controllers\TenantAdmin\TeamController;
use App\Http\Controllers\TenantAdmin\TenantAdminAuthController;
use App\Http\Controllers\TenantAdmin\TenantArticleController;
use App\Http\Controllers\TenantAdmin\TenantEmailSettingsController;
use App\Http\Controllers\TenantAdmin\TenantSettingsController;
use App\Http\Controllers\TenantAdmin\TenantSmsSettingsController;
use App\Http\Controllers\TenantAdmin\TopupController;
use App\Http\Controllers\TenantAdmin\UsersController;
use App\Http\Controllers\TenantAdmin\UserWalletController;
use App\Http\Controllers\TenantAdmin\WithdrawalController;
use App\Http\Middleware\CompanyConfigurationReadOnly;
use Illuminate\Support\Facades\Route;

Route::middleware(['tenant.surface:tenant-admin', CompanyConfigurationReadOnly::class])->prefix('admin')->name('tenant-admin.')->group(function (): void {
    Route::middleware('admin.scope:tenant,support.manage')->group(function (): void {
        Route::get('/support/images/{message}', [SupportController::class, 'image'])->whereUuid('message')->name('support.image');
        Route::get('/support', [SupportController::class, 'index'])->middleware('throttle:60,1')->name('support');
        Route::get('/support/{conversation}', [SupportController::class, 'show'])->whereUuid('conversation')->middleware('throttle:60,1')->name('support.show');
        Route::post('/support/{conversation}/messages', [SupportController::class, 'store'])->whereUuid('conversation')->middleware('throttle:20,1')->name('support.send');
    });
    Route::post('/locale', AdminLocaleController::class)->middleware('throttle:30,1')->name('locale.update');
    Route::get('/login', [TenantAdminAuthController::class, 'create'])->name('login');
    Route::post('/login', [TenantAdminAuthController::class, 'store'])->name('login.store');
    Route::get('/invitations/{token}', [InvitationAcceptanceController::class, 'show'])->where('token', '[a-f0-9]{64}')->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationAcceptanceController::class, 'store'])->where('token', '[a-f0-9]{64}')->name('invitations.accept');

    Route::middleware('admin.scope:tenant,users.read')->group(function (): void {
        Route::get('/demo', DashboardController::class)->name('dashboard');
        Route::get('/users', [UsersController::class, 'index'])->name('users');
        Route::get('/users/{user}', [UsersController::class, 'show'])->whereUuid('user')->name('users.show');
        Route::post('/logout', [TenantAdminAuthController::class, 'destroy'])->name('logout');
    });

    Route::middleware('admin.scope:tenant,users.suspend')->group(function (): void {
        Route::post('/users/{user}/suspend', [UsersController::class, 'suspend'])->whereUuid('user')->name('users.suspend');
        Route::post('/users/{user}/reactivate', [UsersController::class, 'reactivate'])->whereUuid('user')->name('users.reactivate');
    });

    Route::middleware('admin.scope:tenant,wallet.read')->get('/users/{user}/wallet', [UserWalletController::class, 'show'])->whereUuid('user')->name('users.wallet');
    Route::middleware('admin.scope:tenant,ledger.read')->get('/users/{user}/ledger', [UserWalletController::class, 'ledger'])->whereUuid('user')->name('users.ledger');
    Route::middleware('admin.scope:tenant,wallet_topups.read')->group(function (): void {
        Route::get('/topups', [TopupController::class, 'index'])->name('topups.index');
        Route::get('/topups/{topup}', [TopupController::class, 'show'])->whereUuid('topup')->name('topups.show');
    });
    Route::middleware('admin.scope:tenant,withdrawals.read')->group(function (): void {
        Route::get('/withdrawals', [WithdrawalController::class, 'index'])->name('withdrawals.index');
        Route::get('/withdrawals/{withdrawal}', [WithdrawalController::class, 'show'])->whereUuid('withdrawal')->name('withdrawals.show');
    });
    Route::middleware('admin.scope:tenant,card_product.read')->get('/card-products', [CardProductController::class, 'index'])->name('card-products.index');
    Route::middleware('admin.scope:tenant,cards.read')->get('/cards', CardOperationsController::class)->name('cards.index');
    Route::middleware('admin.scope:tenant,card_product.manage')->put('/card-products/{cardProduct}', [CardProductController::class, 'update'])->whereUuid('cardProduct')->name('card-products.update');
    Route::middleware('admin.scope:tenant,withdrawals.review')->group(function (): void {
        Route::post('/withdrawals/recent-auth', [AdminRecentAuthenticationController::class, 'store'])->middleware('throttle:5,1')->name('withdrawals.recent-auth');
        Route::post('/withdrawals/{withdrawal}/approve', [WithdrawalController::class, 'approve'])->whereUuid('withdrawal')->name('withdrawals.approve');
        Route::post('/withdrawals/{withdrawal}/reject', [WithdrawalController::class, 'reject'])->whereUuid('withdrawal')->name('withdrawals.reject');
        Route::post('/withdrawals/{withdrawal}/verify', [WithdrawalController::class, 'verify'])->whereUuid('withdrawal')->middleware('throttle:10,1')->name('withdrawals.verify');
    });
    Route::middleware(['admin.scope:tenant,withdrawals.review', 'admin.recent-auth'])->post('/withdrawals/{withdrawal}/reveal', [WithdrawalController::class, 'reveal'])->whereUuid('withdrawal')->middleware('throttle:10,1')->name('withdrawals.reveal');

    Route::middleware('admin.scope:tenant,kyc.read')->group(function (): void {
        Route::get('/kyc', [KycController::class, 'index'])->name('kyc.index');
    });
    Route::middleware('admin.scope:tenant,kyc.review')->group(function (): void {
        Route::get('/kyc/{kyc}', [KycController::class, 'show'])->whereUuid('kyc')->name('kyc.show');
        Route::post('/kyc/{kyc}/approve', [KycController::class, 'approve'])->whereUuid('kyc')->name('kyc.approve');
        Route::post('/kyc/{kyc}/reject', [KycController::class, 'reject'])->whereUuid('kyc')->name('kyc.reject');
        Route::post('/kyc/{kyc}/resubmission', [KycController::class, 'requireResubmission'])->whereUuid('kyc')->name('kyc.resubmission');
    });
    Route::middleware('admin.scope:tenant,kyc.document.view')->post('/recent-auth', [AdminRecentAuthenticationController::class, 'store'])->middleware('throttle:5,1')->name('recent-auth.store');
    Route::middleware(['admin.scope:tenant,kyc.document.view', 'admin.recent-auth'])->group(function (): void {
        Route::post('/kyc/{kyc}/documents/{side}/access', [KycDocumentController::class, 'access'])->whereUuid('kyc')->whereIn('side', ['front', 'back'])->middleware('throttle:kyc-documents')->name('kyc.documents.access');
        Route::get('/kyc/{kyc}/documents/{side}', [KycDocumentController::class, 'show'])->whereUuid('kyc')->whereIn('side', ['front', 'back'])->middleware(['signed', 'throttle:kyc-documents'])->name('kyc.documents.show');
    });

    Route::middleware('admin.scope:tenant,tenant_settings.manage')->group(function (): void {
        Route::get('/wealth', [App\Http\Controllers\Platform\WealthController::class, 'read']);
        Route::get('/promotion', [PromotionController::class, 'show'])->name('promotion');
        Route::post('/promotion', [PromotionController::class, 'update'])->middleware('throttle:20,1')->name('promotion.update');
        Route::get('/company-funds', [PromotionController::class, 'funds'])->name('company-funds');
        Route::get('/', [OnboardingController::class, 'show'])->name('home');
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
    });

    Route::middleware('admin.scope:tenant,tenant.activate')->post('/onboarding/activate', [OnboardingController::class, 'activate'])->name('activate');

    Route::middleware('admin.scope:tenant,admin_team.read')->get('/team', [TeamController::class, 'index'])->name('team');
    Route::middleware('admin.scope:tenant,admin_team.manage')->group(function (): void {
        Route::post('/team/administrators', [TeamController::class, 'store'])->middleware('throttle:5,1')->name('team.create');
        Route::post('/team/invitations', [TeamController::class, 'invite'])->name('team.invite');
        Route::post('/team/invitations/{invitation}/resend', [TeamController::class, 'resend'])->whereUuid('invitation')->name('team.resend');
        Route::post('/team/invitations/{invitation}/cancel', [TeamController::class, 'cancel'])->whereUuid('invitation')->name('team.cancel');
    });
});
