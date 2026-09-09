<?php

use App\Http\Controllers\TenantAdmin\DashboardController;
use App\Http\Controllers\TenantAdmin\SettingsController;
use App\Http\Controllers\TenantAdmin\UsersController;
use Illuminate\Support\Facades\Route;

Route::middleware('tenant.surface:tenant-admin')->prefix('admin')->name('tenant-admin.')->group(function (): void {
    Route::get('/login', fn () => inertia('tenant-admin/Login'))->name('login');
    Route::get('/demo', DashboardController::class)->name('dashboard');
    Route::get('/demo/users', UsersController::class)->name('users');
    Route::get('/demo/settings', SettingsController::class)->name('settings');
});
