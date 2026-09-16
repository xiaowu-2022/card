<?php

namespace App\Support\Logging;

use App\Infrastructure\Providers\Card\PhotonPayTransactionNormalizer;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Complete callback evidence is encrypted before reaching any logging channel. */
final class PhotonPayWebhookPayloadLog
{
    public function capture(#[\SensitiveParameter] string $body, #[\SensitiveParameter] string $signature, string $category, string $type): void
    {
        try {
            if (strlen($body) > 2097152 || strlen($signature) > 2048 || strlen($category) > 128 || strlen($type) > 128) {
                PhotonPayLog::write('webhook.payload_unavailable', ['reason' => 'payload_too_large'], true);

                return;
            }
            $configured = (string) config('card-provider.photonpay.webhook_log_key');
            $key = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : false;
            if (! is_string($key) || strlen($key) !== 32) {
                PhotonPayLog::write('webhook.payload_unavailable', ['reason' => 'log_key_invalid'], true);

                return;
            }
            $cipher = new Encrypter($key, 'aes-256-gcm');
            $requestId = app()->bound('request') ? request()->attributes->get('request_id') : null;
            $requestId = is_string($requestId) && Str::isUuid($requestId) ? $requestId : null;
            // Preserve exact signed bytes, including invalid JSON and empty/malformed fields.
            // Encrypt header values too: no signature, arbitrary header text or raw body in logs.
            $sealed = $cipher->encryptString(json_encode([
                'request_id' => $requestId,
                'body_base64' => base64_encode($body),
                'signature_base64' => base64_encode($signature),
                'category_base64' => base64_encode($category),
                'type_base64' => base64_encode($type),
            ], JSON_THROW_ON_ERROR));
            $visible = [];
            try {
                $json = (new PhotonPayTransactionNormalizer)->decode($body);
                if (str_starts_with(ltrim($body), '{')) {
                    $visible = $this->visibleParameters($json);
                }
            } catch (\Throwable) {
                // Original invalid bytes remain recoverable from the encrypted envelope.
            }
            Log::channel('photonpay_webhooks')->info('photonpay.webhook.payload', [
                'request_id' => $requestId, 'notification_ref' => PhotonPayLog::reference($body),
                'body_bytes' => strlen($body), 'schema_version' => 1,
                'key_ref' => substr(hash('sha256', $key), 0, 16), 'cipher' => 'aes-256-gcm',
                'unverified_parameters' => $visible, 'encrypted_envelope' => $sealed,
            ]);
        } catch (\Throwable) {
            // Diagnostic failures must not change acknowledgement, settlement or retry behavior.
            PhotonPayLog::write('webhook.payload_unavailable', ['reason' => 'payload_log_failed'], true);
        }
    }

    /** Unknown/free-text fields are retained in ciphertext, never guessed to be safe. */
    private function visibleParameters(array $json): array
    {
        $visible = [];
        foreach ($json as $field => $value) {
            if (! is_string($value)) {
                continue;
            }
            $pattern = match ($field) {
                'cardId', 'cardholderId', 'transactionId', 'originTransactionId', 'requestId', 'memberId', 'matrixAccount' => '/^[A-Za-z0-9_-]{0,180}$/D',
                'transactionAmount', 'arrivalAmount', 'feeDeductionAmount', 'feeReturnAmount', 'txnPrincipalChangeAmount', 'settleAmount' => '/^-?[0-9]{1,12}(?:\.[0-9]{1,8})?$/D',
                'transactionCurrency', 'feeDeductionCurrency', 'feeReturnCurrency', 'txnPrincipalChangeCurrency', 'settleCurrency' => '/^[A-Z]{3}$/D',
                'code' => '/^(?:0000|VCC[0-9]{4})$/D',
                'createdAt', 'updatedAt', 'transactionHappenedAt' => '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})?$/D',
                'transactionType' => '/^(?:auth|verification|void|refund|recharge|recharge_return|discard_recharge_return|service_fee|refund_reversal|fund_in|atm_inquiry|atm_withdrawals|corrective_auth|corrective_refund|corrective_refund_void)$/D',
                'status', 'transactionStatus', 'cardStatus', 'cardholderReviewStatus', 'produceStatus' => '/^(?:normal|frozen|cancelled|pending|processing|succeed|failed|authorized|void|approved|rejected)$/D',
                'arrivalAccount', 'feeDeductionAccount', 'feeReturnAccount', 'txnPrincipalChangeAccount' => '/^(?:card|member|matrix)$/D',
                'taxIndicator' => '/^[YN]$/D',
                'mcc' => '/^[0-9]{4}$/D',
                'transactionInitiatorType' => '/^(?:manual|api|system)$/D',
                default => null,
            };
            if ($pattern !== null && preg_match($pattern, $value)
                && ! preg_match('/[0-9]{12,19}/', $value)) {
                // A list avoids generic redactors mistaking provider code for an OTP key.
                $visible[] = ['field' => $field, 'value' => $value];
            }
        }

        return $visible;
    }
}
