<?php

namespace App\Domain\Kyc\Services;

final readonly class IdentityNumberProtector
{
    public function __construct(
        private IdentityNumberNormalizer $normalizer,
        private IdentityHashGenerator $hashes,
        private KycDataCipher $cipher,
    ) {}

    /** @return array{normalized:string, encrypted:string, hash:string} */
    public function protect(string $tenantId, string $documentType, string $documentCountry, string $identityNumber): array
    {
        $normalized = $this->normalizer->normalize($identityNumber);

        return [
            'normalized' => $normalized,
            'encrypted' => $this->cipher->encrypt($normalized),
            'hash' => $this->hashes->generate($tenantId, $documentType, $documentCountry, $normalized),
        ];
    }

    public function decrypt(string $encrypted): string
    {
        return $this->cipher->decrypt($encrypted);
    }

    public function maskEncrypted(string $encrypted): string
    {
        $value = $this->decrypt($encrypted);
        $last = mb_substr($value, -4, null, 'UTF-8');

        return str_repeat('*', max(4, mb_strlen($value, 'UTF-8') - 4)).$last;
    }
}
