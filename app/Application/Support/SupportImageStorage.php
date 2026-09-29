<?php

namespace App\Application\Support;

use App\Application\Media\ImageStorage;
use App\Application\Media\VerifiedDirectImage;
use App\Support\Errors\DomainException;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class SupportImageStorage
{
    public function prepare(#[\SensitiveParameter] UploadedFile|VerifiedDirectImage|null $image): ?array
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

        return ['upload' => $image, 'contents' => $contents, 'mime' => $mime, 'hash' => hash_hmac('sha256', $contents, (string) config('app.key'))];
    }

    public function store(string $tenantId, #[\SensitiveParameter] array $image, ?string $reference = null): string
    {
        $path = 'support/'.$tenantId.'/'.Str::uuid().'.enc';
        app(ImageStorage::class)->putUpload($tenantId, 'private', $path, $image['upload'], 'support', $reference, 'support');

        return $path;
    }

    public function read(#[\SensitiveParameter] string $path): string
    {
        return app(ImageStorage::class)->read('private', $path, 'support');
    }

    public function response(string $path): Response
    {
        return app(ImageStorage::class)->displayResponse('private', $path, 'preview', 'support');
    }

    /** Only the caller's newly staged, proven-unreferenced object may be discarded. */
    public function discardStaged(string $tenantId, #[\SensitiveParameter] string $path): void
    {
        $prefix = 'support/'.$tenantId.'/';
        if (! str_starts_with($path, $prefix) || ! preg_match('/^[a-f0-9-]{36}\.enc$/D', substr($path, strlen($prefix)))) {
            throw new \LogicException('Invalid staged image scope.');
        }
        app(ImageStorage::class)->discard('private', $path);
    }
}
