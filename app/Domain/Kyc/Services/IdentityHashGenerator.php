<?php

namespace App\Domain\Kyc\Services;

use RuntimeException;

final class IdentityHashGenerator
{
    public function generate(string $tenantId, string $documentType, string $documentCountry, string $normalizedIdentityNumber): string
    {
        $key = (string) config('kyc.identity_hash_key');
        if (strlen($key) < 32) {
            throw new RuntimeException('KYC_IDENTITY_HASH_KEY must contain at least 32 characters.');
        }

        return hash_hmac('sha256', $this->canonicalPayload([
            'identity-hash-v1',
            strtolower(trim($tenantId)),
            strtoupper(trim($documentType)),
            strtoupper(trim($documentCountry)),
            $normalizedIdentityNumber,
        ]), $key);
    }

    public function advisoryLockKey(string $identityHash): int
    {
        if (preg_match('/^[a-f0-9]{64}$/', $identityHash) !== 1) {
            throw new RuntimeException('Identity hash is malformed.');
        }

        return intval(substr(hash('sha256', "kyc-approval-lock-v1\0".$identityHash), 0, 15), 16);
    }

    /** @param list<string> $parts */
    private function canonicalPayload(array $parts): string
    {
        return implode('', array_map(
            static fn (string $part): string => pack('N', strlen($part)).$part,
            $parts,
        ));
    }
}
