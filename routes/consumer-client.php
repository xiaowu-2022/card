<?php

use App\Http\Controllers\Platform\CompanyConfiguration\InvitationPosterController;
use App\Http\Controllers\Public\LandingController;
use App\Http\Controllers\User\AboutController;
use App\Http\Controllers\User\AccountController;
use App\Http\Controllers\User\AssetsController;
use App\Http\Controllers\User\CardIssueController;
use App\Http\Controllers\User\CardManagementController;
use App\Http\Controllers\User\CardRecipientController;
use App\Http\Controllers\User\CardsController;
use App\Http\Controllers\User\CardSetupController;
use App\Http\Controllers\User\CardTransactionsController;
use App\Http\Controllers\User\DashboardController;
use App\Http\Controllers\User\ForgotPasswordController;
use App\Http\Controllers\User\KycController;
use App\Http\Controllers\User\PaidPromotionController;
use App\Http\Controllers\User\PartnerReportController;
use App\Http\Controllers\User\PhysicalCardActivationController;
use App\Http\Controllers\User\PromotionController;
use App\Http\Controllers\User\RegistrationController;
use App\Http\Controllers\User\SecurityDepositController;
use App\Http\Controllers\User\SupportController;
use App\Http\Controllers\User\UserAuthController;
use App\Http\Controllers\User\UserLocaleController;
use App\Http\Controllers\User\WalletController;
use App\Http\Controllers\User\WalletTopupController;
use App\Http\Controllers\User\WalletTransferController;
use App\Http\Controllers\User\WealthController;
use App\Http\Controllers\User\WithdrawalController;
use App\Http\Middleware\ThrottleCardTransactionReads;
use Illuminate\Support\Facades\Route;

// Explicit consumer routes only. Reuse current controllers, validation, throttles and actions.
// No web HTML, admin routes, development fixtures or named-route overrides.
Route::middleware('tenant.surface:end-user')->group(function (): void {
    Route::get('/', LandingController::class);
    Route::middleware(\App\Http\Middleware\RequireConsumerApiGuest::class)->group(function (): void {
        Route::get('/register', [RegistrationController::class, 'create']);
        Route::post('/register/challenges', [RegistrationController::class, 'storeChallenge']);
        Route::get('/register/challenges/{challenge}', [RegistrationController::class, 'showChallenge'])->whereUuid('challenge');
        Route::post('/register/challenges/{challenge}/verify', [RegistrationController::class, 'verify'])->whereUuid('challenge');
        Route::post('/register/challenges/{challenge}/complete', [RegistrationController::class, 'complete'])->whereUuid('challenge');
    });

});

Route::middleware('tenant.surface:user-auth')->group(function (): void {
    Route::post('/locale', UserLocaleController::class)->middleware('throttle:30,1');
    Route::middleware(\App\Http\Middleware\RequireConsumerApiGuest::class)->group(function (): void {
        Route::get('/forgot-password', [ForgotPasswordController::class, 'create']);
        Route::post('/forgot-password', [ForgotPasswordController::class, 'start'])->middleware('throttle:5,1');
        Route::get('/forgot-password/{reset}', [ForgotPasswordController::class, 'show'])->whereUuid('reset')->middleware('throttle:30,1');
        Route::post('/forgot-password/{reset}', [ForgotPasswordController::class, 'complete'])->whereUuid('reset')->middleware('throttle:5,1');
    });

    Route::middleware([\App\Http\Middleware\RequireConsumerApiUser::class, 'tenant.surface:user-restricted'])->group(function (): void {
        Route::post('/support/read', [SupportController::class, 'read'])->middleware('throttle:120,1');
        Route::get('/messages', [\App\Http\Controllers\User\InboxController::class, 'index']);
        Route::get('/messages/unread-count', [\App\Http\Controllers\User\InboxController::class, 'unread'])->middleware('throttle:120,1');
        Route::post('/messages/read-all', [\App\Http\Controllers\User\InboxController::class, 'readAll'])->middleware('throttle:30,1');
        Route::get('/messages/{message}', [\App\Http\Controllers\User\InboxController::class, 'show'])->whereUuid('message');
        Route::post('/messages/{message}/read', [\App\Http\Controllers\User\InboxController::class, 'read'])->whereUuid('message')->middleware('throttle:120,1');
        Route::get('/account/restricted', [AccountController::class, 'restricted']);
        Route::get('/account/security', [AccountController::class, 'security']);
        Route::get('/kyc', [KycController::class, 'show']);
        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/top-up', [WalletTopupController::class, 'index']);
        Route::get('/wallet/top-ups/{topup}/return', [WalletTopupController::class, 'returned'])->whereUuid('topup');
        Route::post('/account/security/password', [AccountController::class, 'changePassword'])->middleware('throttle:5,1');
        Route::post('/account/security/sessions/revoke', [AccountController::class, 'revokeSessions'])->middleware('throttle:5,1');
    });
});

Route::middleware(['tenant.surface:end-user', \App\Http\Middleware\RequireConsumerApiUser::class, \App\Http\Middleware\RequireConsumerApiUser::class.':operational'])->group(function (): void {
    Route::post('/account/information/name', [AccountController::class, 'updateName'])->middleware('throttle:10,1');
    Route::post('/account/information/contacts', [AccountController::class, 'startContact'])->middleware('throttle:5,1');
    Route::post('/account/information/contacts/{change}/verify', [AccountController::class, 'completeContact'])->whereUuid('change')->middleware('throttle:5,1');
    Route::get('/support', [SupportController::class, 'show'])->middleware('throttle:60,1');
    Route::get('/support/images/{message}', [SupportController::class, 'image'])->whereUuid('message');
    Route::post('/support/messages', [SupportController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/funds', [AssetsController::class, 'funds']);
    Route::get('/assets/{asset}/activity', [AssetsController::class, 'history'])->whereIn('asset', ['USDT', 'USDC', 'ETH', 'BTC']);
    Route::get('/wealth', [WealthController::class, 'index']);
    Route::get('/wealth/assets/{asset}', [WealthController::class, 'asset'])->whereIn('asset', ['USDT', 'USDC', 'ETH', 'BTC']);
    Route::get('/wealth/orders/{order}', [WealthController::class, 'show'])->whereUuid('order');
    Route::post('/wealth/orders', [WealthController::class, 'store'])->middleware('throttle:10,1');
    Route::post('/wealth/orders/{order}/redeem', [WealthController::class, 'redeem'])->whereUuid('order')->middleware('throttle:5,1');
    Route::post('/wealth/orders/{order}/cancel', [WealthController::class, 'cancel'])->whereUuid('order')->middleware('throttle:5,1');
    Route::get('/assets/operate', [AssetsController::class, 'show']);
    Route::post('/assets/orders', [AssetsController::class, 'store'])->middleware('throttle:10,1');
    Route::post('/assets/exchanges/{order}/confirm', [AssetsController::class, 'confirm'])->whereUuid('order')->middleware('throttle:10,1');
    Route::post('/assets/withdrawals/{order}/cancel', [AssetsController::class, 'cancel'])->whereUuid('order')->middleware('throttle:10,1');
    Route::get('/dashboard', DashboardController::class);
    Route::get('/wallet/transfer', [WalletTransferController::class, 'show']);
    Route::post('/wallet/transfers', [WalletTransferController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/wallet/transfers/{transfer}', [WalletTransferController::class, 'show'])->whereUuid('transfer');
    Route::get('/cards', [CardsController::class, 'index']);
    Route::post('/cards/{card}/management', CardManagementController::class)
        ->whereUuid('card')->middleware('throttle:cards');
    Route::post('/cards/{card}/transactions/sync', [CardTransactionsController::class, 'sync'])
        ->whereUuid('card')->middleware(ThrottleCardTransactionReads::class);
    Route::get('/cards/{card}/transactions', [CardTransactionsController::class, 'index'])
        ->whereUuid('card')->middleware(ThrottleCardTransactionReads::class);
    Route::post('/cards/{card}/activation/sync', [PhysicalCardActivationController::class, 'sync'])->whereUuid('card')->middleware('throttle:cards');
    Route::post('/cards/{card}/activate', [PhysicalCardActivationController::class, 'store'])->whereUuid('card')->middleware('throttle:cards');
    Route::post('/cards/recipients/{recipient}/inspect', [CardRecipientController::class, 'inspect'])->whereUuid('recipient')->middleware('throttle:cards');
    Route::post('/cards/recipients', [CardRecipientController::class, 'store'])->middleware('throttle:cards');
    Route::post('/cards/cardholder', [CardSetupController::class, 'store'])->middleware('throttle:cards');
    Route::post('/cards/cardholder/{application}/details', [CardSetupController::class, 'details'])->whereUuid('application')->middleware('throttle:cards');
    Route::post('/cards/cardholder/{application}/sync', [CardSetupController::class, 'sync'])->whereUuid('application')->middleware('throttle:cards');
    Route::post('/cards/issues', [CardIssueController::class, 'store'])->middleware('throttle:cards');
    Route::post('/cards/issues/{issue}/sync', [CardIssueController::class, 'sync'])->whereUuid('issue')->middleware('throttle:cards');
    Route::get('/account/settings', [AccountController::class, 'settings']);
    Route::get('/account', [AccountController::class, 'show']);
    Route::get('/promotion/membership', [PaidPromotionController::class, 'show']);
    Route::get('/promotion/rewards', [PaidPromotionController::class, 'details']);
    Route::post('/promotion/quotes', [PaidPromotionController::class, 'quote'])->middleware('throttle:10,1');
    Route::post('/promotion/quotes/{order}/confirm', [PaidPromotionController::class, 'confirm'])->whereUuid('order')->middleware('throttle:5,1');
    Route::get('/promotion/poster-background', [InvitationPosterController::class, 'userImage']);
    Route::get('/promotion', [PromotionController::class, 'show']);
    Route::get('/promotion/stock', PartnerReportController::class)->middleware('throttle:60,1');
    Route::get('/promotion/members/{member}/team-summary', [PromotionController::class, 'memberTeam'])->whereUuid('member')->middleware('throttle:60,1');
    Route::get('/promotion/commissions', [PromotionController::class, 'commissions']);
    Route::get('/promotion/{section}', [PromotionController::class, 'show'])->whereIn('section', ['team', 'daily', 'direct', 'invitations', 'rules', 'features', 'reward-guide', 'registration']);
    Route::get('/about', [AboutController::class, 'index']);
    Route::get('/about/{article}', [AboutController::class, 'show'])->whereIn('article', ['terms', 'privacy', 'account-closure']);
    Route::post('/kyc/applications', [KycController::class, 'store']);
    Route::post('/wallet/activate', [WalletController::class, 'activate']);
    Route::post('/wallet/top-ups', [WalletTopupController::class, 'store'])->middleware('throttle:wallet-topups');
    Route::get('/security-deposit', [SecurityDepositController::class, 'show']);
    Route::get('/security-deposit/history', [SecurityDepositController::class, 'history']);
    Route::post('/security-deposit/top-ups', [WalletTopupController::class, 'deposit'])->middleware('throttle:wallet-topups');
    Route::post('/security-deposit/fund', [SecurityDepositController::class, 'fund'])->middleware('throttle:security-deposit-funding');
    Route::post('/security-deposit/refund', [SecurityDepositController::class, 'refund'])->middleware('throttle:5,1');
    Route::get('/security-deposit/success', [SecurityDepositController::class, 'success']);
    Route::get('/wallet/withdraw', [WithdrawalController::class, 'create']);
    Route::get('/wallet/withdrawals', [WithdrawalController::class, 'index']);
    Route::post('/wallet/withdrawal-destinations', [WithdrawalController::class, 'storeDestination'])->middleware('throttle:withdrawals');
    Route::post('/wallet/withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:withdrawals');
    Route::get('/wallet/withdrawals/{withdrawal}', [WithdrawalController::class, 'show'])->whereUuid('withdrawal');
    Route::post('/wallet/withdrawals/{withdrawal}/cancel', [WithdrawalController::class, 'cancel'])->whereUuid('withdrawal')->middleware('throttle:withdrawals');


});

