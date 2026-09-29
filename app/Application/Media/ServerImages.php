<?php

namespace App\Application\Media;

use App\Domain\Media\OssConfiguration;
use App\Domain\Media\StoredImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ServerImages
{
    public static function enabled(): bool
    {
        return (DB::table('media_storage_settings')->where('id', 1)->value('storage_driver') ?? config('media.storage', 'server')) === 'server';
    }

    public function read(StoredImage $image): string
    {
        if ($image->backup_key) {
            try {
                return app(ImageReplicas::class)->read($image);
            } catch (\Throwable) { /* Try retained, checksum-verified original copies. */
            }
        }
        if ($image->configuration_id) {
            $bucket = OssConfiguration::findOrFail($image->configuration_id)->bucket;
            $key = $image->object_key;
            if (preg_match('/^[a-z0-9-]+$/D', $bucket) && ! str_starts_with($key, '/') && ! str_contains($key, '..') && ! str_contains($key, '\\')) {
                $path = storage_path('app/private/oss-pull/mirror/'.$bucket.'/'.$key);
                if (is_file($path)) {
                    return $this->verify($image, file_get_contents($path));
                }
            }
        }
        $disk = Storage::disk($image->source_disk);
        if ($disk->exists($image->source_key)) {
            $codec = $image->codec;
            if ($image->configuration_id) {
                foreach (app(ImageReferences::class)->all($image->tenant_id) as $ref) {
                    if ($ref['disk'] === $image->source_disk && $ref['key'] === $image->source_key) {
                        $codec = $ref['codec'];
                        break;
                    }
                }
            }

            return $this->verify($image, app(ImageStorage::class)->decode($disk->get($image->source_key), $codec));
        }
        throw new \RuntimeException('Server image original unavailable');
    }

    private function verify(StoredImage $image, string $bytes): string
    {
        if (! $image->sha256 || $image->sha256 === str_repeat('0', 64) || ! hash_equals($image->sha256, hash('sha256', $bytes))) {
            throw new \RuntimeException('Server image checksum mismatch');
        }

        return $bytes;
    }
}
