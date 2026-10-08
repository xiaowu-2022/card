<?php

namespace App\Domain\Kyc\Services;

final class NationalIdNumber
{
    public function normalize(#[\SensitiveParameter] string $value): ?string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value;
        }
        $number = strtoupper((string) preg_replace('/\s+/u', '', $value));
        if (! preg_match('/^[1-9][0-9]{16}[0-9X]$/D', $number)
            || substr($number, 6, 8) >= now()->format('Ymd')
            || ! checkdate((int) substr($number, 10, 2), (int) substr($number, 12, 2), (int) substr($number, 6, 4))) {
            return null;
        }
        $sum = 0;
        foreach ([7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2] as $i => $weight) {
            $sum += (int) $number[$i] * $weight;
        }
        return $number[17] === '10X98765432'[$sum % 11] ? $number : null;
    }
}
