<?php

namespace App\Http\Controllers\Webhooks;

use App\Application\Card\ReceiveCardNotificationAction;
use App\Http\Controllers\Controller;
use App\Support\Errors\DomainException;
use App\Support\Logging\PhotonPayLog;
use App\Support\Logging\PhotonPayWebhookPayloadLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PhotonPayWebhookController extends Controller
{
    public function __invoke(Request $request, ReceiveCardNotificationAction $action): JsonResponse
    {
        PhotonPayLog::write('webhook.received');
        app(PhotonPayWebhookPayloadLog::class)->capture($request->getContent(), (string) $request->header('X-PD-SIGN'),
            (string) $request->header('X-PD-NOTIFICATION-CATAGORY'), (string) $request->header('X-PD-NOTIFICATION-TYPE'));
        try {
            $action->execute($request->getContent(), (string) $request->header('X-PD-SIGN'),
                (string) $request->header('X-PD-NOTIFICATION-CATAGORY'), (string) $request->header('X-PD-NOTIFICATION-TYPE'), $request->route('account'));

            PhotonPayLog::write('webhook.acknowledged', ['http_status' => 200]);

            return response()->json(['roger' => true]);
        } catch (DomainException $exception) {
            PhotonPayLog::write('webhook.rejected', ['http_status' => $exception->httpStatus, 'failure' => PhotonPayLog::failure($exception)] + array_intersect_key($exception->details, array_flip([
                'notification_ref', 'notification_fields', 'body_bytes', 'category', 'notification_type_ref', 'signature_verified', 'stage', 'reason', 'field', 'field_state',
            ])), true);

            return response()->json(['roger' => false], $exception->httpStatus);
        } catch (\Throwable $exception) {
            PhotonPayLog::write('webhook.failed', ['http_status' => 503, 'failure' => PhotonPayLog::failure($exception)], true);

            return response()->json(['roger' => false], 503);
        }
    }
}
