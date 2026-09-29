<?php

namespace App\Application\Media;

use App\Domain\Media\StoredImage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ImageReplicas
{
    public function save(StoredImage $image, #[\SensitiveParameter] string $bytes): void
    {
        $hash = hash('sha256', $bytes);
        if ($image->backup_sha256 && ! hash_equals($image->backup_sha256, $hash)) {
            throw new \RuntimeException('Image replica mismatch');
        }
        $key = 'image-replicas/'.$image->tenant_id.'/'.$image->id.'/'.$hash;
        $temporary = $key.'.'.Str::uuid().'.tmp';
        if (! Storage::disk('private')->put($temporary, Crypt::encryptString($bytes))
            || ! Storage::disk('private')->move($temporary, $key)) {
            Storage::disk('private')->delete($temporary);
            throw new \RuntimeException('Image replica unavailable');
        }
        $image->update(['backup_key' => $key, 'backup_sha256' => $hash]);
    }

    public function read(StoredImage $image): string
    {
        if (! $image->backup_key || ! $image->backup_sha256) {
            throw new \RuntimeException('Image replica unavailable');
        }
        $bytes = Crypt::decryptString(Storage::disk('private')->get($image->backup_key));
        if (! hash_equals($image->backup_sha256, hash('sha256', $bytes))
            || ($image->sha256 && $image->sha256 !== str_repeat('0', 64) && ! hash_equals($image->sha256, hash('sha256', $bytes)))) {
            throw new \RuntimeException('Image replica mismatch');
        }

        return $bytes;
    }
}
