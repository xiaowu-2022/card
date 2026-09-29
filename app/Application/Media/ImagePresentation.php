<?php

namespace App\Application\Media;

/** Server-owned OSS display policies; originals remain byte-for-byte unchanged. */
final class ImagePresentation
{
    public static function process(string $profile, string $mime): ?string
    {
        [$edge, $quality] = match ($profile) {
            'brand' => [512, 80],
            'preview' => [1600, 80],
            'document', 'poster' => [2048, 85],
            default => throw new \InvalidArgumentException('Unknown image display profile'),
        };
        // ICO is not an OSS IMG input format. Its upload already has a 512 KiB cap.
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        return "image/resize,m_lfit,w_{$edge},h_{$edge},limit_1/format,webp/quality,Q_{$quality}";
    }
}
