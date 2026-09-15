<?php

namespace App\Domain\CardProvider;

final class ProviderReference
{
    public const TEST_PATTERN = '^(MOCK|TEST|DEMO)([-_:]|$)';

    public static function isTest(?string $value): bool
    {
        return $value !== null && preg_match('/'.self::TEST_PATTERN.'/i', trim($value)) === 1;
    }
}
