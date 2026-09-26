<?php

use App\Http\Controllers\Api\ConsumerController;
use App\Http\Middleware\RequireConsumerApiUser;
use Illuminate\Support\Facades\Route;

Route::get('bootstrap', [ConsumerController::class, 'bootstrap'])->middleware(['throttle:120,1', \App\Http\Middleware\ConsumerFlowSession::class]);
Route::prefix('client')->middleware([
    \App\Http\Middleware\ConsumerFlowSession::class,
    \App\Http\Middleware\ConsumerPageResponse::class,
])->group(base_path('routes/consumer-client.php'));
Route::post('login', [ConsumerController::class, 'login'])->middleware('throttle:20,1');
Route::middleware(RequireConsumerApiUser::class)->group(function (): void {
    Route::post('logout', [ConsumerController::class, 'logout']);
    Route::post('locale', [ConsumerController::class, 'locale'])->middleware('throttle:30,1');
    Route::get('account', [ConsumerController::class, 'account']);
    Route::get('unread', [ConsumerController::class, 'unread'])->middleware('throttle:120,1');
    Route::get('messages', [ConsumerController::class, 'messages']);
    Route::post('messages/read-all', [ConsumerController::class, 'read'])->middleware('throttle:30,1');
    Route::get('messages/{message}', [ConsumerController::class, 'message'])->whereUuid('message');
    Route::post('messages/{message}/read', [ConsumerController::class, 'read'])->whereUuid('message')->middleware('throttle:120,1');
    Route::post('support/read', [ConsumerController::class, 'supportRead'])->middleware('throttle:120,1');
});
Route::middleware(RequireConsumerApiUser::class.':operational')->group(function (): void {
    Route::get('assets', [ConsumerController::class, 'assets']);
    Route::get('cards', [ConsumerController::class, 'cards']);
    Route::get('support', [ConsumerController::class, 'support'])->middleware('throttle:60,1');
    Route::get('support/images/{message}', [ConsumerController::class, 'supportImage'])->whereUuid('message');
    Route::post('support/messages', [ConsumerController::class, 'supportSend'])->middleware('throttle:20,1');
});
