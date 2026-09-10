<?php

namespace App\Domain\Withdrawal\Services;

use App\Support\Errors\DomainException;

final class WithdrawalAddressProtector
{
    public function normalize(string $address): string
    {
        $address = trim($address);
        if (preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address) !== 1) {
            throw new DomainException('WITHDRAWAL_ADDRESS_INVALID', 'Enter a valid TRON address.');
        }

        return $address;
    }

    public function encrypt(string $address): string
    {
        $key = $this->key('withdrawal.address_encryption_key');
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($address, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new DomainException('WITHDRAWAL_ADDRESS_PROTECTION_FAILED', 'The withdrawal address could not be protected.', 500);
        }

        return base64_encode($iv.$tag.$ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new DomainException('WITHDRAWAL_ADDRESS_PROTECTION_FAILED', 'The withdrawal address could not be read.', 500);
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key('withdrawal.address_encryption_key'), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new DomainException('WITHDRAWAL_ADDRESS_PROTECTION_FAILED', 'The withdrawal address could not be read.', 500);
        }

        return $plain;
    }

    public function hash(string $tenantId, string $userId, string $address): string
    {
        return hash_hmac('sha256', "withdrawal-address-v1\0{$tenantId}\0{$userId}\0{$address}", $this->key('withdrawal.address_hash_key'));
    }

    public function mask(string $address): string
    {
        return substr($address, 0, 6).'…'.substr($address, -5);
    }

    private function key(string $config): string
    {
        $configured = config($config);
        $key = is_string($configured) && str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : $configured;
        if (! is_string($key) || strlen($key) !== 32) {
            throw new DomainException('WITHDRAWAL_PROTECTION_KEY_UNAVAILABLE', 'Withdrawal address protection is unavailable.', 503);
        }

        return $key;
    }
}
