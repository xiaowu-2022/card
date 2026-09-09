<?php

namespace App\Domain\Kyc\Services;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

final class KycDataCipher
{
    private Encrypter $encrypter;

    public function __construct()
    {
        $configured = (string) config('kyc.data_encryption_key');
        $key = str_starts_with($configured, 'base64:')
            ? base64_decode(substr($configured, 7), true)
            : $configured;

        if (! is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('KYC_DATA_ENCRYPTION_KEY must be a 32-byte key, optionally base64 encoded.');
        }

        $this->encrypter = new Encrypter($key, 'AES-256-GCM');
    }

    public function encrypt(string $plaintext): string
    {
        return $this->encrypter->encryptString($plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        return $this->encrypter->decryptString($ciphertext);
    }
}
