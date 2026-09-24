<?php

namespace App\Support\Logging;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;

final class PhotonPayRequestPayloadLog
{
    public static function write(array $context, string $direction, #[\SensitiveParameter] string $body): void
    {
        try {
            $configured = (string) config('card-provider.photonpay.request_log_key');
            $key = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : false;
            if (! is_string($key) || strlen($key) !== 32) {
                PhotonPayLog::write('request.payload_unavailable', ['reason' => 'log_key_invalid'], true);

                return;
            }
            if (strlen($body) > 2097152) {
                PhotonPayLog::write('request.payload_unavailable', ['reason' => 'payload_too_large'], true);

                return;
            }
            $envelope = ['context' => $context, 'direction' => $direction, 'body_base64' => base64_encode($body)];
            $sealed = (new Encrypter($key, 'aes-256-gcm'))->encryptString(json_encode($envelope, JSON_THROW_ON_ERROR));
            Log::channel('photonpay_requests')->info('photonpay.request.payload', [
                'span_id' => $context['span_id'] ?? null,
                'request_id' => app()->bound('request') ? request()->attributes->get('request_id') : null,
                'direction' => $direction, 'schema_version' => 1,
                'key_ref' => substr(hash('sha256', $key), 0, 16),
                'encrypted_envelope' => $sealed,
            ]);
        } catch (\Throwable) {
            PhotonPayLog::write('request.payload_unavailable', ['reason' => 'payload_log_failed'], true);
        }
    }
}
