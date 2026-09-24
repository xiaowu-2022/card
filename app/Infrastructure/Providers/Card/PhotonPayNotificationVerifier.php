<?php

namespace App\Infrastructure\Providers\Card;

use App\Support\Errors\DomainException;
use App\Support\Logging\PhotonPayLog;

final class PhotonPayNotificationVerifier
{
    /** Only signature-verified, allowlisted identifiers leave this boundary. */
    public function verify(#[\SensitiveParameter] string $body, string $signature, string $category, string $type, ?string $accountPublicKey = null): array
    {
        $diagnostics = ['body_bytes' => strlen($body), 'category' => $category,
            'notification_type_ref' => PhotonPayLog::reference($type)];
        $key = str_replace('\\n', "\n", ($accountPublicKey ?? (string) config('card-provider.photonpay.webhook_public_key')));
        $public = @openssl_pkey_get_public($key);
        $details = $public ? openssl_pkey_get_details($public) : false;
        // PhotonPay notification keys can be RSA-1024. This is separate from
        // merchant request-signing keys; the exact-body signature remains mandatory.
        if (! $details || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < 1024) {
            throw new DomainException('CARD_WEBHOOK_UNAVAILABLE', 'Notification verification is unavailable.', 503, $diagnostics + [
                'stage' => 'signature_verification', 'reason' => 'verification_key_invalid', 'signature_verified' => false,
            ]);
        }
        $decoded = strlen($signature) <= 2048 ? base64_decode($signature, true) : false;
        if (strlen($body) > 2097152 || ! is_string($decoded) || @openssl_verify($body, $decoded, $public, OPENSSL_ALGO_MD5) !== 1) {
            throw new DomainException('CARD_WEBHOOK_INVALID', 'Invalid notification.', 401, $diagnostics + [
                'stage' => 'signature_verification', 'reason' => strlen($body) > 2097152 ? 'body_too_large' : 'signature_invalid',
                'signature_verified' => false,
            ]);
        }
        $diagnostics['signature_verified'] = true;
        $diagnostics['notification_ref'] = PhotonPayLog::reference($body);
        if (! in_array($category, ['issuing', 'issuing_settlement', 'issuing_card'], true) || ! preg_match('/^[a-z_]{1,40}$/', $type)) {
            throw new DomainException('CARD_WEBHOOK_UNSUPPORTED', 'Unsupported notification.', 422, $diagnostics + [
                'stage' => 'notification_headers', 'reason' => 'unsupported_headers',
            ]);
        }
        try {
            $json = (new PhotonPayTransactionNormalizer)->decode($body);
        } catch (\Throwable) {
            throw new DomainException('CARD_WEBHOOK_INVALID', 'Invalid notification.', 422, $diagnostics + [
                'stage' => 'notification_body', 'reason' => 'invalid_json',
            ]);
        }
        if (! str_starts_with(ltrim($body), '{')) {
            throw new DomainException('CARD_WEBHOOK_INVALID', 'Invalid notification.', 422, $diagnostics + [
                'stage' => 'notification_body', 'reason' => 'object_required',
            ]);
        }
        $diagnostics['notification_fields'] = [];
        foreach (['cardId', 'cardholderId', 'transactionId', 'requestId'] as $field) {
            $value = $json[$field] ?? null;
            $state = match (true) {
                ! array_key_exists($field, $json) => 'missing',
                $value === null => 'null',
                $value === '' => 'empty',
                ! is_string($value) => 'wrong_type',
                strlen($value) > 180 => 'too_long',
                ! preg_match('/^[A-Za-z0-9_-]{1,180}$/D', $value) => 'invalid_characters',
                default => 'valid',
            };
            $diagnostics['notification_fields'][$field] = ['state' => $state];
            if ($state === 'valid') {
                $diagnostics['notification_fields'][$field]['ref'] = PhotonPayLog::reference($value);
            }
        }
        $result = ['category' => $category, 'event_type' => $type];
        foreach (['cardId', 'cardholderId', 'transactionId', 'requestId'] as $field) {
            $value = $json[$field] ?? null;
            // Optional provider identifiers may be absent, null or an empty string.
            // Resource mapping still requires a verified, nonempty known identifier.
            if ($value === '') {
                $value = null;
            }
            if ($value !== null && (! is_string($value) || ! preg_match('/^[A-Za-z0-9_-]{1,180}$/D', $value))) {
                throw new DomainException('CARD_WEBHOOK_INVALID', 'Invalid notification.', 422, $diagnostics + [
                    'stage' => 'notification_identifiers', 'reason' => 'identifier_invalid', 'field' => $field,
                    'field_state' => is_string($value) ? (strlen($value) > 180 ? 'too_long' : 'invalid_characters') : 'wrong_type',
                ]);
            }
            $result[$field] = $value;
        }
        $result['digest'] = hash_hmac('sha256', $body, (string) config('app.key'));

        PhotonPayLog::write('webhook.validated', $diagnostics);

        return $result;
    }
}
