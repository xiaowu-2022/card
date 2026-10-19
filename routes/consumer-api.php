<?php

use App\Http\Controllers\Api\AppReleaseController;
use App\Http\Controllers\Api\ConsumerController;
use App\Http\Controllers\Api\ConsumerTokenBridgeController;
use App\Http\Controllers\Api\DirectImageUploadController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\SupportWorkspaceController;
use App\Http\Controllers\SupportRealtimeController;
use App\Http\Middleware\ConsumerFlowSession;
use App\Http\Middleware\ConsumerPageResponse;
use App\Http\Middleware\RequireConsumerApiUser;
use App\Http\Middleware\ResolveUserLocale;
use App\Http\Middleware\ThrottleConsumerBackground;
use App\Http\Middleware\ThrottleConsumerMedia;
use Illuminate\Support\Facades\Route;

Route::get('app-debug', [AppReleaseController::class, 'debug'])->middleware('business-throttle:240,1,consumer-debug:');
Route::get('app-release', AppReleaseController::class)->middleware('business-throttle:240,1,consumer-release:');
Route::get('domains', [ConsumerController::class, 'domains'])->middleware('throttle:consumer-domains')->withoutMiddleware(ResolveUserLocale::class);
Route::get('session', [ConsumerController::class, 'session'])->middleware('business-throttle:240,1,consumer-session:')->withoutMiddleware(ResolveUserLocale::class);
// Only the cookie-independent transport exposes the native credential mailbox.
Route::middleware('business-throttle:120,1,consumer-token-bridge:')->group(function (): void {
    Route::post('session/bridge/open', [ConsumerTokenBridgeController::class, 'open']);
    Route::post('session/bridge/claim', [ConsumerTokenBridgeController::class, 'claim']);
    Route::post('session/bridge/publish', [ConsumerTokenBridgeController::class, 'publish'])
        ->middleware(RequireConsumerApiUser::class);
});
Route::get('bootstrap', [ConsumerController::class, 'bootstrap'])->middleware(['business-throttle:240,1,consumer-bootstrap:', ConsumerFlowSession::class]);
Route::prefix('client')->middleware([
    ConsumerFlowSession::class,
    ConsumerPageResponse::class,
])->group(base_path('routes/consumer-client.php'));
Route::post('login', [ConsumerController::class, 'login'])->middleware('throttle:20,1,consumer-login:');
Route::middleware(RequireConsumerApiUser::class)->group(function (): void {
    Route::get('home/summary', [HomeController::class, 'summary'])->middleware(RequireConsumerApiUser::class.':operational');
    Route::get('home/activity', [HomeController::class, 'activity'])->middleware(RequireConsumerApiUser::class.':operational');
    Route::post('wallet/ensure', [ConsumerController::class, 'ensureWallet'])->middleware('business-throttle:240,1');
    Route::post('logout', [ConsumerController::class, 'logout']);
    Route::post('locale', [ConsumerController::class, 'locale'])->middleware('business-throttle:60,1,consumer-locale:');
    Route::get('account', [ConsumerController::class, 'account']);
    Route::get('unread', [ConsumerController::class, 'unread'])->middleware('business-throttle:240,1');
    Route::get('messages', [ConsumerController::class, 'messages']);
    Route::post('messages/read-all', [ConsumerController::class, 'read'])->middleware('business-throttle:60,1');
    Route::get('messages/{message}', [ConsumerController::class, 'message'])->whereUuid('message');
    Route::post('messages/{message}/read', [ConsumerController::class, 'read'])->whereUuid('message')->middleware('business-throttle:240,1');
    Route::post('presence', [ConsumerController::class, 'presence'])->middleware(ThrottleConsumerBackground::class.':presence');
    Route::post('support/read', [ConsumerController::class, 'supportRead'])->middleware('business-throttle:240,1');
});
Route::middleware(RequireConsumerApiUser::class.':operational')->group(function (): void {
    Route::get('support/realtime', [SupportRealtimeController::class, 'configuration'])->middleware('business-throttle:120,1');
    Route::post('support/realtime/auth', [SupportRealtimeController::class, 'authorize'])->middleware('business-throttle:240,1');
    Route::post('images/direct', [DirectImageUploadController::class, 'store'])->middleware(ThrottleConsumerMedia::class.':image-upload-authorize');
    Route::post('images/direct/{upload}/backup', [DirectImageUploadController::class, 'backup'])->whereUuid('upload')->middleware(ThrottleConsumerMedia::class.':image-upload-backup');
    Route::post('images/direct/{upload}/complete', [DirectImageUploadController::class, 'complete'])->whereUuid('upload')->middleware(ThrottleConsumerMedia::class.':image-upload-complete');
    Route::get('assets', [ConsumerController::class, 'assets']);
    Route::get('cards', [ConsumerController::class, 'cards']);
    Route::get('support', [ConsumerController::class, 'support'])->middleware('business-throttle:120,1');
    Route::get('support/images/{message}', [ConsumerController::class, 'supportImage'])->whereUuid('message');
    Route::post('support/handoff', [ConsumerController::class, 'supportHandoff'])->middleware('business-throttle:40,1');
    Route::post('support/messages', [ConsumerController::class, 'supportSend'])->middleware('business-throttle:40,1');
});

Route::prefix('support-workspace')->middleware(RequireConsumerApiUser::class.':operational')->group(function () {
    $controller = SupportWorkspaceController::class;
    Route::get('/', [$controller, 'index'])->middleware('business-throttle:120,1');
    Route::post('/profile', [$controller, 'profile'])->middleware('business-throttle:40,1');
    Route::get('/replies', [$controller, 'replies']);
    Route::post('/replies', [$controller, 'saveReply'])->middleware('business-throttle:60,1');
    Route::get('/images/{message}', [$controller, 'image'])->whereUuid('message');
    Route::get('/conversations/{conversation}', [$controller, 'show'])->whereUuid('conversation')->middleware('business-throttle:120,1');
    Route::post('/conversations/{conversation}/messages', [$controller, 'send'])->whereUuid('conversation')->middleware('business-throttle:60,1');
    Route::post('/conversations/{conversation}/messages/{message}/change', [$controller, 'changeMessage'])->whereUuid('conversation')->whereUuid('message')->middleware('business-throttle:60,1');
    Route::post('/conversations/{conversation}/remark', [$controller, 'remark'])->whereUuid('conversation')->middleware('business-throttle:60,1');
    Route::get('/conversations/{conversation}/customer', [$controller, 'customer'])->whereUuid('conversation')->middleware('business-throttle:120,1');
    Route::post('/conversations/{conversation}/read', [$controller, 'read'])->whereUuid('conversation')->middleware('business-throttle:120,1');
    Route::post('/conversations/{conversation}/finish', [$controller, 'finish'])->whereUuid('conversation')->middleware('business-throttle:40,1');
});
