<?php

namespace App\Domain\Admin\Services;

final class AdminLoginAccount
{
    public static function normalize(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    /** @return list<string> */
    public static function rules(): array
    {
        return ['required', 'string', 'max:255', 'not_regex:/[\\s\\p{C}]/u'];
    }
}
