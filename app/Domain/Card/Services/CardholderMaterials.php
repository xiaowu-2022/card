<?php

namespace App\Domain\Card\Services;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

final class CardholderMaterials
{
    private Encrypter $cipher;

    private string $hashKey;

    public function __construct()
    {
        // Reuse the stable persistent-data root, not any account's KYC values or documents.
        // Domain-separated derived keys prevent cross-purpose ciphertext/hash reuse.
        $configured = (string) config('kyc.data_encryption_key');
        $root = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : $configured;
        if (! is_string($root) || strlen($root) !== 32) {
            throw new RuntimeException('The persistent sensitive-data encryption key is unavailable.');
        }
        $this->cipher = new Encrypter(hash_hkdf('sha256', $root, 32, 'cardholder-materials-encryption-v1'), 'AES-256-GCM');
        $this->hashKey = hash_hkdf('sha256', $root, 32, 'cardholder-materials-idempotency-v1');
    }

    public function encrypt(string $value): string
    {
        return $this->cipher->encryptString($value);
    }

    public function decrypt(string $value): string
    {
        return $this->cipher->decryptString($value);
    }

    public function fingerprint(string $tenantId, string $userId, string $productId, string $canonicalMaterials): string
    {
        return hash_hmac('sha256', json_encode(['cardholder-v1', $tenantId, $userId, $productId, $canonicalMaterials], JSON_THROW_ON_ERROR), $this->hashKey);
    }
}
