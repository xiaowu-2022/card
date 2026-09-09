<?php

use App\Http\Controllers\Public\LandingController;
use App\Http\Controllers\User\CardsController;
use App\Http\Controllers\User\DashboardController;
use App\Http\Controllers\User\WalletController;
use Illuminate\Support\Facades\Route;

Route::middleware('tenant.surface:end-user')->group(function (): void {
    Route::get('/', LandingController::class)->name('public.landing');
    Route::get('/login', fn () => inertia('user/Login'))->name('user.login');
    Route::get('/demo', DashboardController::class)->name('user.dashboard');
    Route::get('/demo/wallet', WalletController::class)->name('user.wallet');
    Route::get('/demo/cards', CardsController::class)->name('user.cards');
});

if (app()->environment('testing')) {
    Route::get('/__tenant/context', fn () => response()->json(['tenant_id' => request()->attributes->get('tenant_id')]))
        ->name('tenant.context');
}
