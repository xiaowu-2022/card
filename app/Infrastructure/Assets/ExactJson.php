<?php

namespace App\Infrastructure\Assets;

final class ExactJson
{
    /** Preserve JSON number lexemes instead of passing money through a float. */
    public static function decode(string $json): array
    {
        $quoted = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"(*SKIP)(*F)|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/', fn ($m) => json_encode($m[0], JSON_THROW_ON_ERROR), $json);
        $value = json_decode($quoted, true, 128, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new \UnexpectedValueException('Invalid JSON object.');
        }

        return $value;
    }
}
