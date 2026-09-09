<?php

use App\Http\Controllers\Platform\DashboardController;
use App\Http\Controllers\Platform\TenantsController;
use Illuminate\Support\Facades\Route;

Route::prefix('platform')->name('platform.')->group(function (): void {
    Route::get('/login', fn () => inertia('platform/Login'))->name('login');
    Route::get('/demo', DashboardController::class)->name('dashboard');
    Route::get('/demo/tenants', TenantsController::class)->name('tenants');
});
