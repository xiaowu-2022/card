<?php

use App\Http\Controllers\Public\LandingController;
use App\Http\Controllers\User\AboutController;
use App\Http\Controllers\User\AccountController;
use App\Http\Controllers\User\CardholderTestMaterialsController;
use App\Http\Controllers\User\CardIssueController;
use App\Http\Controllers\User\CardManagementController;
use App\Http\Controllers\User\CardsController;
use App\Http\Controllers\User\CardSetupController;
use App\Http\Controllers\User\CardTransactionsController;
use App\Http\Controllers\User\DashboardController;
use App\Http\Controllers\User\DemoWalletController;
use App\Http\Controllers\User\ForgotPasswordController;
use App\Http\Controllers\User\KycController;
use App\Http\Controllers\User\MockPaymentController;
use App\Http\Controllers\User\MockTrc20TopupController;
use App\Http\Controllers\User\PromotionController;
use App\Http\Controllers\User\RegistrationController;
use App\Http\Controllers\User\SecurityDepositController;
use App\Http\Controllers\User\SupportController;
use App\Http\Controllers\User\UserAuthController;
use App\Http\Controllers\User\UserLocaleController;
use App\Http\Controllers\User\WalletController;
use App\Http\Controllers\User\WalletTopupController;
use App\Http\Controllers\User\WalletTransferController;
use App\Http\Controllers\User\WithdrawalController;
use App\Http\Middleware\ThrottleCardTransactionReads;
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
        Route::get('/demo/wallet', DemoWalletController::class)->name('user.demo.wallet');
        Route::get('/demo/cards', [CardsController::class, 'demo'])->name('user.cards');
    }
});

Route::middleware('tenant.surface:user-auth')->group(function (): void {
    Route::post('/locale', UserLocaleController::class)->middleware('throttle:30,1')->name('user.locale.update');
    Route::middleware('guest:tenant_user')->group(function (): void {
        Route::get('/login', [UserAuthController::class, 'create'])->name('user.login');
        Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('user.password-reset.create');
        Route::post('/forgot-password', [ForgotPasswordController::class, 'start'])->middleware('throttle:5,1')->name('user.password-reset.start');
        Route::get('/forgot-password/{reset}', [ForgotPasswordController::class, 'show'])->whereUuid('reset')->middleware('throttle:30,1')->name('user.password-reset.show');
        Route::post('/forgot-password/{reset}', [ForgotPasswordController::class, 'complete'])->whereUuid('reset')->middleware('throttle:5,1')->name('user.password-reset.complete');
        Route::post('/login', [UserAuthController::class, 'store'])->name('user.login.store');
    });

    Route::middleware(['user.authenticated', 'tenant.surface:user-restricted'])->group(function (): void {
        Route::get('/account/restricted', [AccountController::class, 'restricted'])->name('user.account.restricted');
        Route::get('/account/security', [AccountController::class, 'security'])->name('user.account.security');
        Route::get('/kyc', [KycController::class, 'show'])->name('user.kyc');
        Route::get('/wallet', [WalletController::class, 'show'])->name('user.wallet');
        Route::get('/wallet/top-up', [WalletTopupController::class, 'index'])->name('user.topups.index');
        Route::get('/wallet/top-ups/{topup}/return', [WalletTopupController::class, 'returned'])->whereUuid('topup')->name('user.topups.return');
        Route::post('/account/security/password', [AccountController::class, 'changePassword'])->middleware('throttle:5,1')->name('user.account.password');
        Route::post('/account/security/sessions/revoke', [AccountController::class, 'revokeSessions'])->middleware('throttle:5,1')->name('user.account.sessions.revoke');
        Route::post('/logout', [UserAuthController::class, 'destroy'])->name('user.logout');
    });
});

Route::middleware(['tenant.surface:end-user', 'user.authenticated', 'user.operational'])->group(function (): void {
    Route::post('/account/information/name', [AccountController::class, 'updateName'])->middleware('throttle:10,1')->name('user.account.name');
    Route::post('/account/information/contacts', [AccountController::class, 'startContact'])->middleware('throttle:5,1')->name('user.account.contact.start');
    Route::post('/account/information/contacts/{change}/verify', [AccountController::class, 'completeContact'])->whereUuid('change')->middleware('throttle:5,1')->name('user.account.contact.verify');
    Route::get('/support', [SupportController::class, 'show'])->middleware('throttle:60,1')->name('user.support');
    Route::get('/support/images/{message}', [SupportController::class, 'image'])->whereUuid('message')->name('user.support.image');
    Route::post('/support/messages', [SupportController::class, 'store'])->middleware('throttle:20,1')->name('user.support.send');
    Route::get('/dashboard', DashboardController::class)->name('user.authenticated.dashboard');
    Route::get('/wallet/transfer', [WalletTransferController::class, 'show'])->name('user.transfers.create');
    Route::post('/wallet/transfers', [WalletTransferController::class, 'store'])->middleware('throttle:5,1')->name('user.transfers.store');
    Route::get('/wallet/transfers/{transfer}', [WalletTransferController::class, 'show'])->whereUuid('transfer')->name('user.transfers.show');
    Route::get('/cards', [CardsController::class, 'index'])->name('user.authenticated.cards');
    Route::post('/cards/{card}/management', CardManagementController::class)
        ->whereUuid('card')->middleware('throttle:cards')->name('user.cards.management');
    Route::post('/cards/{card}/transactions/sync', [CardTransactionsController::class, 'sync'])
        ->whereUuid('card')->middleware(ThrottleCardTransactionReads::class)->name('user.cards.transactions.sync');
    Route::get('/cards/{card}/transactions', [CardTransactionsController::class, 'index'])
        ->whereUuid('card')->middleware(ThrottleCardTransactionReads::class)->name('user.cards.transactions');
    Route::post('/cards/cardholder/test-materials/save', [CardholderTestMaterialsController::class, 'store'])->middleware('throttle:5,1')->name('user.cards.test-materials.save');
    Route::post('/cards/cardholder/test-materials/{product}', [CardholderTestMaterialsController::class, 'read'])->whereUuid('product')->middleware('throttle:5,1')->name('user.cards.test-materials.read');
    Route::post('/cards/cardholder', [CardSetupController::class, 'store'])->middleware('throttle:cards')->name('user.cards.cardholder.store');
    Route::post('/cards/cardholder/{application}/details', [CardSetupController::class, 'details'])->whereUuid('application')->middleware('throttle:cards')->name('user.cards.cardholder.details');
    Route::post('/cards/cardholder/{application}/sync', [CardSetupController::class, 'sync'])->whereUuid('application')->middleware('throttle:cards')->name('user.cards.cardholder.sync');
    Route::post('/cards/issues', [CardIssueController::class, 'store'])->middleware('throttle:cards')->name('user.cards.issues.store');
    Route::post('/cards/issues/{issue}/sync', [CardIssueController::class, 'sync'])->whereUuid('issue')->middleware('throttle:cards')->name('user.cards.issues.sync');
    Route::get('/account/settings', [AccountController::class, 'settings'])->name('user.account.settings');
    Route::get('/account', [AccountController::class, 'show'])->name('user.account');
    Route::get('/promotion', [PromotionController::class, 'show'])->name('user.promotion');
    Route::get('/promotion/commissions', [PromotionController::class, 'commissions'])->name('user.promotion.commissions');
    Route::get('/promotion/{section}', [PromotionController::class, 'show'])->whereIn('section', ['team', 'daily', 'direct'])->name('user.promotion.section');
    Route::post('/promotion', [PromotionController::class, 'update'])->middleware('throttle:10,1')->name('user.promotion.update');
    Route::get('/about', [AboutController::class, 'index'])->name('user.about');
    Route::get('/about/{article}', [AboutController::class, 'show'])->whereIn('article', ['terms', 'privacy', 'account-closure'])->name('user.about.article');
    Route::post('/kyc/applications', [KycController::class, 'store'])->name('user.kyc.applications.store');
    Route::post('/wallet/activate', [WalletController::class, 'activate'])->name('user.wallet.activate');
    Route::post('/wallet/top-ups', [WalletTopupController::class, 'store'])->middleware('throttle:wallet-topups')->name('user.topups.store');
    Route::get('/security-deposit', [SecurityDepositController::class, 'show'])->name('user.security-deposit.show');
    Route::get('/security-deposit/history', [SecurityDepositController::class, 'history'])->name('user.security-deposit.history');
    Route::post('/security-deposit/top-ups', [WalletTopupController::class, 'deposit'])->middleware('throttle:wallet-topups')->name('user.security-deposit.topups');
    Route::post('/security-deposit/fund', [SecurityDepositController::class, 'fund'])->middleware('throttle:security-deposit-funding')->name('user.security-deposit.fund');
    Route::post('/security-deposit/refund', [SecurityDepositController::class, 'refund'])->middleware('throttle:5,1')->name('user.security-deposit.refund');
    Route::get('/security-deposit/success', [SecurityDepositController::class, 'success'])->name('user.security-deposit.success');
    Route::get('/wallet/withdraw', [WithdrawalController::class, 'create'])->name('user.withdrawals.create');
    Route::get('/wallet/withdrawals', [WithdrawalController::class, 'index'])->name('user.withdrawals.index');
    Route::post('/wallet/withdrawal-destinations', [WithdrawalController::class, 'storeDestination'])->middleware('throttle:withdrawals')->name('user.withdrawal-destinations.store');
    Route::post('/wallet/withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:withdrawals')->name('user.withdrawals.store');
    Route::get('/wallet/withdrawals/{withdrawal}', [WithdrawalController::class, 'show'])->whereUuid('withdrawal')->name('user.withdrawals.show');
    Route::post('/wallet/withdrawals/{withdrawal}/cancel', [WithdrawalController::class, 'cancel'])->whereUuid('withdrawal')->middleware('throttle:withdrawals')->name('user.withdrawals.cancel');

    if (app()->environment(['local', 'testing'])) {
        Route::get('/__mock/payments/{providerRequest}', [MockPaymentController::class, 'show'])->whereUuid('providerRequest')->name('mock-payments.show');
        Route::post('/__mock/payments/{providerRequest}/complete', [MockPaymentController::class, 'complete'])->whereUuid('providerRequest')->name('mock-payments.complete');
        Route::post('/__mock/topups/{topup}/complete', [MockTrc20TopupController::class, 'store'])->whereUuid('topup')->name('mock-trc20-topups.complete');
    }
});

if (app()->environment('testing')) {
    Route::get('/__tenant/context', fn () => response()->json(['tenant_id' => request()->attributes->get('tenant_id')]))
        ->name('tenant.context');
}
