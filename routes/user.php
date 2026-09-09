<?php

use App\Http\Controllers\Public\LandingController;
use App\Http\Controllers\User\AccountController;
use App\Http\Controllers\User\CardsController;
use App\Http\Controllers\User\DashboardController;
use App\Http\Controllers\User\RegistrationController;
use App\Http\Controllers\User\UserAuthController;
use App\Http\Controllers\User\WalletController;
use Illuminate\Support\Facades\Route;

Route::middleware('tenant.surface:end-user')->group(function (): void {
    Route::get('/', LandingController::class)->name('public.landing');
    Route::middleware('guest:tenant_user')->group(function (): void {
        Route::get('/register', [RegistrationController::class, 'create'])->name('user.register');
        Route::post('/register/challenges', [RegistrationController::class, 'storeChallenge'])->name('user.registration.challenge.store');
        Route::get('/register/challenges/{challenge}', [RegistrationController::class, 'showChallenge'])->whereUuid('challenge')->name('user.registration.challenge.show');
        Route::post('/register/challenges/{challenge}/verify', [RegistrationController::class, 'verify'])->whereUuid('challenge')->name('user.registration.challenge.verify');
        Route::post('/register/challenges/{challenge}/complete', [RegistrationController::class, 'complete'])->whereUuid('challenge')->name('user.registration.complete');
    });
    if (app()->environment(['local', 'testing'])) {
        Route::get('/demo', DashboardController::class)->name('user.dashboard');
        Route::get('/demo/wallet', WalletController::class)->name('user.wallet');
        Route::get('/demo/cards', CardsController::class)->name('user.cards');
    }
});

Route::middleware('tenant.surface:user-auth')->group(function (): void {
    Route::middleware('guest:tenant_user')->group(function (): void {
        Route::get('/login', [UserAuthController::class, 'create'])->name('user.login');
        Route::post('/login', [UserAuthController::class, 'store'])->name('user.login.store');
    });

    Route::middleware(['user.authenticated', 'tenant.surface:user-restricted'])->group(function (): void {
        Route::get('/account/restricted', [AccountController::class, 'restricted'])->name('user.account.restricted');
        Route::get('/account/security', [AccountController::class, 'security'])->name('user.account.security');
        Route::post('/account/security/password', [AccountController::class, 'changePassword'])->middleware('throttle:5,1')->name('user.account.password');
        Route::post('/logout', [UserAuthController::class, 'destroy'])->name('user.logout');
    });
});

Route::middleware(['tenant.surface:end-user', 'user.authenticated', 'user.operational'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('user.authenticated.dashboard');
    Route::get('/account', [AccountController::class, 'show'])->name('user.account');
});

if (app()->environment('testing')) {
    Route::get('/__tenant/context', fn () => response()->json(['tenant_id' => request()->attributes->get('tenant_id')]))
        ->name('tenant.context');
}
