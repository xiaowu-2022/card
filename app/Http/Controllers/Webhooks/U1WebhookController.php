<?php

namespace App\Http\Controllers\Webhooks;

use App\Application\CardProviderDirectory\U1Accounts;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessU1Notification;
use App\Infrastructure\Assets\ExactJson;
use App\Infrastructure\Providers\Card\U1NotificationVerifier;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class U1WebhookController extends Controller
{
    public function __invoke(string $connection, Request $request, U1Accounts $accounts)
    {
        $receiptId = (string) Str::uuid();
        $body = $request->getContent();
        try {
            $raw = strlen($body) <= 262144 ? ExactJson::decode($body) : [];
        } catch (\Throwable) {
            $raw = [];
        }
        $type = $raw['type'] ?? null;
        $context = ['receipt_id' => $receiptId, 'connection_id' => $connection,
            'notification_type' => is_string($type) && isset(U1NotificationVerifier::FIELDS[$type]) ? $type : 'UNKNOWN',
            'received_at_beijing' => now()->timezone('Asia/Shanghai')->toIso8601String(), 'body_bytes' => strlen($body)];
        $formats = [];
        foreach (['type', 'cardNo', 'cardno', 'apiId', 'timestamp', 'otp', 'sign'] as $field) {
            $formats[$field] = ['present' => array_key_exists($field, $raw), 'type' => gettype($raw[$field] ?? null)];
        }
        try {
            $this->writeLog('U1 webhook arrived', $context + ['field_formats' => $formats,
                'body_encrypted' => strlen($body) <= 262144 ? Crypt::encryptString($body) : null]);
        } catch (\Throwable) {
            $this->writeLog('U1 webhook arrived', $context + ['reason' => 'BODY_ENCRYPTION_UNAVAILABLE']);
        }
        try {
            $facts = $accounts->receive($connection, $request->getContent());
        } catch (RecordsNotFoundException|ModelNotFoundException) {
            $this->writeLog('U1 webhook rejected', $context + ['http_status' => 404, 'reason' => 'NOT_FOUND']);
            return response('NOT_FOUND', 404)->header('Cache-Control', 'no-store');
        } catch (HttpExceptionInterface $error) {
            $this->writeLog('U1 webhook rejected', $context + ['http_status' => $error->getStatusCode(), 'reason' => 'REJECTED']);
            return response('REJECTED', $error->getStatusCode())->header('Cache-Control', 'no-store');
        } catch (\Throwable) {
            $this->writeLog('U1 webhook failed', $context + ['http_status' => 503, 'reason' => 'STORAGE_OR_KEY_FAILURE']);
            // Never acknowledge a storage/key failure or report raw callback/OTP content.
            return response('RETRY', 503)->header('Cache-Control', 'no-store');
        }

        $this->writeLog('U1 webhook verified', $context + ['notification_id' => $facts['id'], 'verified' => $facts['verified'], 'reason' => $facts['reason']]);
        if ($facts['verified']) {
            ProcessU1Notification::dispatch($connection, $facts['id'])->afterCommit();
        }

        return response($facts['verified'] ? 'SUCCESS' : 'INVALID_NOTIFICATION', $facts['verified'] ? 200 : 400)
            ->header('Content-Type', 'text/plain; charset=UTF-8')->header('Cache-Control', 'no-store');
    }

    private function writeLog(string $message, array $context): void
    {
        try {
            Log::channel('u1_notifications')->info($message, $context);
        } catch (\Throwable) {
            // Logging must never change the callback acknowledgement or expose raw errors.
        }
    }
}
