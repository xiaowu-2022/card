<?php

use App\Http\Controllers\Webhooks\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/payments/{provider}', PaymentWebhookController::class)
    ->where('provider', '[a-z0-9_-]{2,64}')
    ->middleware('throttle:120,1')
    ->name('webhooks.payments');

Route::post('/card-provider', fn () => response()->json([
    'message' => 'Phase 0 placeholder. Tenant resolution must use trusted provider mappings.',
], 501));
