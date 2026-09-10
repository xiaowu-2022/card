<?php

namespace App\Http\Controllers\Webhooks;

use App\Application\Payment\PersistPaymentProviderEventAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentWebhookController extends Controller
{
    public function __invoke(string $provider, Request $request, PersistPaymentProviderEventAction $action): JsonResponse
    {
        $event = $action->execute($provider, $request);

        return response()->json(['accepted' => true, 'event_id' => $event->id], 202);
    }
}
