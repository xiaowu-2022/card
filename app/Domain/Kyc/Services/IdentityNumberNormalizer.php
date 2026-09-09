<?php

namespace App\Domain\Kyc\Services;

use App\Support\Errors\DomainException;
use Normalizer;

final class IdentityNumberNormalizer
{
    public function normalize(string $value): string
    {
        $value = trim($value);
        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        }

        $value = mb_strtoupper((string) preg_replace('/\s+/u', ' ', $value), 'UTF-8');
        if (mb_strlen($value, 'UTF-8') < 3 || mb_strlen($value, 'UTF-8') > 128 || preg_match('/^[\p{L}\p{M}\p{N}\p{P}\p{Zs}]+$/u', $value) !== 1) {
            throw new DomainException('IDENTITY_NUMBER_INVALID', 'Enter a valid identity number.');
        }

        return $value;
    }
}
