<?php

namespace App\Domain\Kyc\Services;

use Normalizer;

final class IdentityNumberNormalizer
{
    public function normalize(string $value): string
    {
        $value = trim($value);
        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        }

        return mb_strtoupper((string) preg_replace('/\s+/u', ' ', $value), 'UTF-8');
    }
}
