<?php

namespace App\Domain\Kyc\Services;

use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

final readonly class IdentityNumberProtector
{
    public function __construct(private IdentityNumberNormalizer $normalizer) {}

    /** @return array{normalized:string, encrypted:string, hash:string} */
    public function protect(string $tenantId, string $identityNumber): array
    {
        $normalized = $this->normalizer->normalize($identityNumber);
        if (mb_strlen($normalized, 'UTF-8') < 3 || mb_strlen($normalized, 'UTF-8') > 128) {
            throw new DomainException('IDENTITY_NUMBER_INVALID', 'Enter a valid identity number.');
        }
        $key = (string) config('kyc.identity_hash_key');
        if (strlen($key) < 32) {
            throw new RuntimeException('KYC_IDENTITY_HASH_KEY must contain at least 32 characters.');
        }

        return [
            'normalized' => $normalized,
            'encrypted' => Crypt::encryptString($normalized),
            'hash' => hash_hmac('sha256', $tenantId.':'.$normalized, $key),
        ];
    }

    public function decrypt(string $encrypted): string
    {
        return Crypt::decryptString($encrypted);
    }

    public function maskEncrypted(string $encrypted): string
    {
        $value = $this->decrypt($encrypted);
        $last = mb_substr($value, -4, null, 'UTF-8');

        return str_repeat('*', max(4, mb_strlen($value, 'UTF-8') - 4)).$last;
    }
}
