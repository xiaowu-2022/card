<?php

namespace App\Infrastructure\Assets;

final class ExactJson
{
    /** Preserve JSON number lexemes instead of passing money through a float. */
    public static function decode(string $json): array
    {
        if (! json_validate($json, 128)) {
            throw new \UnexpectedValueException('Invalid JSON object.');
        }
        // Possessive string runs avoid exhausting PCRE's JIT stack on large block hex fields.
        $quoted = preg_replace_callback('/"(?:[^"\\\\]++|\\\\.)*+"(*SKIP)(*F)|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/', fn ($m) => json_encode($m[0], JSON_THROW_ON_ERROR), $json);
        if ($quoted === null) {
            throw new \UnexpectedValueException('JSON tokenization failed.');
        }
        $value = json_decode($quoted, true, 128, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new \UnexpectedValueException('Invalid JSON object.');
        }

        return $value;
    }
}
