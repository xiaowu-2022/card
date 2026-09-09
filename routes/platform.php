<?php

use App\Http\Controllers\Platform\DashboardController;
use App\Http\Controllers\Platform\PlatformAuthController;
use App\Http\Controllers\Platform\TenantInvitationController;
use App\Http\Controllers\Platform\TenantLifecycleController;
use App\Http\Controllers\Platform\TenantManagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('platform')->name('platform.')->group(function (): void {
    Route::get('/login', [PlatformAuthController::class, 'create'])->name('login');
    Route::post('/login', [PlatformAuthController::class, 'store'])->name('login.store');

    Route::middleware('admin.scope:platform,tenant.read')->group(function (): void {
        Route::get('/', fn () => redirect('/platform/tenants'))->name('home');
        Route::get('/demo', DashboardController::class)->name('dashboard');
        Route::get('/tenants', [TenantManagementController::class, 'index'])->name('tenants.index');
        Route::get('/tenants/{tenant}', [TenantManagementController::class, 'show'])->whereUuid('tenant')->name('tenants.show');
        Route::post('/logout', [PlatformAuthController::class, 'destroy'])->name('logout');
    });

    Route::middleware('admin.scope:platform,tenant.manage')->group(function (): void {
        Route::get('/tenants/create', [TenantManagementController::class, 'create'])->name('tenants.create');
        Route::post('/tenants', [TenantManagementController::class, 'store'])->name('tenants.store');
        Route::post('/tenants/{tenant}/suspend', [TenantLifecycleController::class, 'suspend'])->whereUuid('tenant')->name('tenants.suspend');
        Route::post('/tenants/{tenant}/reactivate', [TenantLifecycleController::class, 'reactivate'])->whereUuid('tenant')->name('tenants.reactivate');
    });

    Route::middleware('admin.scope:platform,admin_team.manage')->group(function (): void {
        Route::post('/tenants/{tenant}/invitations/{invitation}/resend', [TenantInvitationController::class, 'resend'])->whereUuid(['tenant', 'invitation'])->name('invitations.resend');
        Route::post('/tenants/{tenant}/invitations/{invitation}/cancel', [TenantInvitationController::class, 'cancel'])->whereUuid(['tenant', 'invitation'])->name('invitations.cancel');
    });
});
