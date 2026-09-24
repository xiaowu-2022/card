<?php

use App\Http\Controllers\Webhooks\PaymentWebhookController;
use App\Http\Controllers\Webhooks\PhotonPayWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/payments/{provider}', PaymentWebhookController::class)
    ->where('provider', '[a-z0-9_-]{2,64}')
    ->middleware('throttle:120,1')
    ->name('webhooks.payments');

Route::post('/card-provider', PhotonPayWebhookController::class)->middleware('throttle:300,1')->name('webhooks.photonpay');

Route::post('/card-provider/{account}', PhotonPayWebhookController::class)->whereUuid('account')->middleware('throttle:300,1')->name('webhooks.photonpay.account');
