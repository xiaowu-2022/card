<?php

use App\Http\Controllers\TenantAdmin\DashboardController;
use App\Http\Controllers\TenantAdmin\DomainManagementController;
use App\Http\Controllers\TenantAdmin\InvitationAcceptanceController;
use App\Http\Controllers\TenantAdmin\OnboardingController;
use App\Http\Controllers\TenantAdmin\TeamController;
use App\Http\Controllers\TenantAdmin\TenantAdminAuthController;
use App\Http\Controllers\TenantAdmin\TenantSettingsController;
use App\Http\Controllers\TenantAdmin\UsersController;
use Illuminate\Support\Facades\Route;

Route::middleware('tenant.surface:tenant-admin')->prefix('admin')->name('tenant-admin.')->group(function (): void {
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

    Route::middleware('admin.scope:tenant,tenant_settings.manage')->group(function (): void {
        Route::get('/', [OnboardingController::class, 'show'])->name('home');
        Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
        Route::get('/settings/{section?}', [TenantSettingsController::class, 'show'])->where('section', 'branding|locales|business|kyc')->name('settings');
        Route::post('/settings/branding', [TenantSettingsController::class, 'branding'])->name('settings.branding');
        Route::post('/settings/locales', [TenantSettingsController::class, 'locales'])->name('settings.locales');
        Route::post('/settings/business', [TenantSettingsController::class, 'business'])->name('settings.business');
        Route::post('/settings/kyc', [TenantSettingsController::class, 'kyc'])->name('settings.kyc');
        Route::get('/domains', [DomainManagementController::class, 'index'])->name('domains');
        Route::post('/domains', [DomainManagementController::class, 'store'])->name('domains.store');
        Route::post('/domains/{domain}/verify', [DomainManagementController::class, 'verify'])->whereUuid('domain')->name('domains.verify');
        Route::post('/domains/{domain}/activate', [DomainManagementController::class, 'activate'])->whereUuid('domain')->name('domains.activate');
        Route::post('/domains/{domain}/primary', [DomainManagementController::class, 'primary'])->whereUuid('domain')->name('domains.primary');
        Route::delete('/domains/{domain}', [DomainManagementController::class, 'destroy'])->whereUuid('domain')->name('domains.destroy');
    });

    Route::middleware('admin.scope:tenant,tenant.activate')->post('/onboarding/activate', [OnboardingController::class, 'activate'])->name('activate');

    Route::middleware('admin.scope:tenant,admin_team.read')->get('/team', [TeamController::class, 'index'])->name('team');
    Route::middleware('admin.scope:tenant,admin_team.manage')->group(function (): void {
        Route::post('/team/invitations', [TeamController::class, 'invite'])->name('team.invite');
        Route::post('/team/invitations/{invitation}/resend', [TeamController::class, 'resend'])->whereUuid('invitation')->name('team.resend');
        Route::post('/team/invitations/{invitation}/cancel', [TeamController::class, 'cancel'])->whereUuid('invitation')->name('team.cancel');
    });
});
