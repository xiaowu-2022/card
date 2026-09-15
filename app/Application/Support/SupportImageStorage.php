<?php

namespace App\Application\Support;

use App\Support\Errors\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class SupportImageStorage
{
    public function prepare(#[\SensitiveParameter] ?UploadedFile $image): ?array
    {
        if (! $image) {
            return null;
        }
        $contents = $image->get();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $size = @getimagesizefromstring($contents);
        if (! $image->isValid() || strlen($contents) > 5 * 1024 * 1024 || ! $size
            || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || ($size['mime'] ?? null) !== $mime || $size[0] > 6000 || $size[1] > 6000 || $size[0] * $size[1] > 20000000) {
            throw new DomainException('SUPPORT_IMAGE_INVALID', 'Use a JPG, PNG or WebP image up to 5 MB and 20 megapixels.');
        }

        return ['contents' => $contents, 'mime' => $mime, 'hash' => hash_hmac('sha256', $contents, (string) config('app.key'))];
    }

    public function store(string $tenantId, #[\SensitiveParameter] array $image): string
    {
        $path = 'support/'.$tenantId.'/'.Str::uuid().'.enc';
        if (! Storage::disk('private')->put($path, Crypt::encryptString($image['contents']))) {
            throw new DomainException('SUPPORT_IMAGE_UNAVAILABLE', 'Image upload failed. Please try again.');
        }

        return $path;
    }

    public function read(#[\SensitiveParameter] string $path): string
    {
        $contents = Storage::disk('private')->get($path);
        abort_if($contents === null, 404);

        return Crypt::decryptString($contents);
    }

    /** Only the caller's newly staged, proven-unreferenced object may be discarded. */
    public function discardStaged(string $tenantId, #[\SensitiveParameter] string $path): void
    {
        $prefix = 'support/'.$tenantId.'/';
        if (! str_starts_with($path, $prefix) || ! preg_match('/^[a-f0-9-]{36}\.enc$/D', substr($path, strlen($prefix)))) {
            throw new \LogicException('Invalid staged image scope.');
        }
        if (! Storage::disk('private')->delete($path)) {
            throw new \RuntimeException('Staged image cleanup failed.');
        }
    }
}
