<?php

namespace App\Infrastructure\Providers\Card;

use App\Support\Errors\DomainException;

final class PhotonPayNotificationVerifier
{
    /** Only signature-verified, allowlisted identifiers leave this boundary. */
    public function verify(#[\SensitiveParameter] string $body, string $signature, string $category, string $type): array
    {
        $key = str_replace('\\n', "\n", (string) config('card-provider.photonpay.webhook_public_key'));
        $public = @openssl_pkey_get_public($key);
        if (! $public || (openssl_pkey_get_details($public)['bits'] ?? 0) < 2048) {
            throw new DomainException('CARD_WEBHOOK_UNAVAILABLE', 'Notification verification is unavailable.', 503);
        }
        $decoded = strlen($signature) <= 2048 ? base64_decode($signature, true) : false;
        if (strlen($body) > 2097152 || ! is_string($decoded) || @openssl_verify($body, $decoded, $public, OPENSSL_ALGO_MD5) !== 1) {
            throw new DomainException('CARD_WEBHOOK_INVALID', 'Invalid notification.', 401);
        }
        if (! in_array($category, ['issuing', 'issuing_settlement', 'issuing_card'], true) || ! preg_match('/^[a-z_]{1,40}$/', $type)) {
            throw new DomainException('CARD_WEBHOOK_UNSUPPORTED', 'Unsupported notification.', 422);
        }
        try {
            $json = (new PhotonPayTransactionNormalizer)->decode($body);
        } catch (\Throwable) {
            throw new DomainException('CARD_WEBHOOK_INVALID', 'Invalid notification.', 422);
        }
        $result = ['category' => $category, 'event_type' => $type];
        foreach (['cardId', 'cardholderId', 'transactionId', 'requestId'] as $field) {
            $value = $json[$field] ?? null;
            if ($value !== null && (! is_string($value) || ! preg_match('/^[A-Za-z0-9_-]{1,180}$/', $value))) {
                throw new DomainException('CARD_WEBHOOK_INVALID', 'Invalid notification.', 422);
            }
            $result[$field] = $value;
        }
        $result['digest'] = hash_hmac('sha256', $body, (string) config('app.key'));

        return $result;
    }
}
