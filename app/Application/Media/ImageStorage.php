<?php

namespace App\Application\Media;

use App\Domain\Card\Services\CardholderMaterials;
use App\Domain\Media\OssConfiguration;
use App\Domain\Media\StoredImage;
use App\Infrastructure\Storage\OssImages;
use App\Support\Errors\DomainException;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ImageStorage
{
    public function __construct(private OssImages $oss) {}

    public function active(): ?OssConfiguration
    {
        $id = DB::table('media_storage_settings')->where('id', 1)->value('active_configuration_id');

        return $id ? OssConfiguration::findOrFail($id) : null;
    }

    public function record(string $disk, string $key): ?StoredImage
    {
        return StoredImage::where('source_disk', $disk)->where('source_key', $key)->first();
    }

    public function put(string $tenant, string $disk, string $key, #[\SensitiveParameter] string $contents, string $purpose, ?string $reference = null, string $codec = 'plain'): string
    {
        if (! Str::isUuid($tenant) || str_contains($key, '..') || ! str_contains($key, '/'.$tenant.'/')) {
            throw new \LogicException('Invalid image scope');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'], true)) {
            throw new DomainException('IMAGE_INVALID', 'Use a supported image file.', 422);
        }
        $config = $this->active();
        if (! $config && ! app()->environment('testing')) {
            throw new DomainException('IMAGE_STORAGE_UNAVAILABLE', 'Image storage is unavailable. Please try again.', 503);
        }
        $image = StoredImage::create(['tenant_id' => $tenant, 'source_disk' => $disk, 'source_key' => $key, 'purpose' => $purpose, 'business_reference' => $reference,
            'configuration_id' => $config?->id, 'object_key' => $config ? 'images/'.$tenant.'/'.Str::uuid() : $key,
            'codec' => $config ? 'plain' : $codec, 'mime' => $mime, 'size' => strlen($contents), 'sha256' => hash('sha256', $contents), 'state' => 'uploading', 'cleanup_after' => now()->addDay()]);
        try {
            if ($config) {
                $this->oss->put($config, $image->object_key, $contents, $mime);
            } elseif (! Storage::disk($disk)->put($key, $this->encode($contents, $codec))) {
                throw new \RuntimeException('Storage failed');
            }
            $image->update(['state' => 'ready']);
        } catch (\Throwable) {
            try {
                $this->discard($disk, $key);
            } catch (\Throwable) { /* The durable upload intent remains for recovery. */
            }
            throw new DomainException('IMAGE_STORAGE_UNAVAILABLE', 'Image storage is unavailable. Please try again.', 503);
        }

        return $key;
    }

    public function putUpload(string $tenant, string $disk, string $key, UploadedFile|VerifiedDirectImage $file, string $purpose, ?string $reference = null, string $codec = 'plain'): string
    {
        if ($file instanceof VerifiedDirectImage) {
            return app(DirectImageUploads::class)->claim($file, $tenant, $disk, $key, $purpose, $reference);
        }

        return $this->put($tenant, $disk, $key, $file->getContent(), $purpose, $reference, $codec);
    }

    public function read(string $disk, string $key, string $legacyCodec = 'plain'): string
    {
        $image = $this->record($disk, $key);
        if ($image && $image->state !== 'ready') {
            abort(404);
        }
        if ($image?->configuration_id) {
            return $this->oss->get(OssConfiguration::findOrFail($image->configuration_id), $image->object_key);
        }
        $bytes = Storage::disk($disk)->get($key);
        abort_unless(is_string($bytes), 404);

        return $this->decode($bytes, $image?->codec ?? $legacyCodec);
    }

    public function url(string $disk, ?string $key): ?string
    {
        if (! $key) {
            return null;
        }
        $image = $this->record($disk, $key);
        if ($image && $image->state !== 'ready') {
            abort(404);
        }
        if ($image?->configuration_id) {
            return $this->oss->url(OssConfiguration::findOrFail($image->configuration_id), $image->object_key);
        }

        return url(Storage::disk($disk)->url($key));
    }

    public function displayUrl(string $disk, ?string $key, string $profile = 'preview'): ?string
    {
        $url = $this->url($disk, $key);
        $image = $key ? $this->record($disk, $key) : null;
        $process = $image?->configuration_id ? ImagePresentation::process($profile, $image->mime) : null;

        return $process ? $url.'?'.http_build_query(['x-oss-process' => $process], '', '&', PHP_QUERY_RFC3986) : $url;
    }

    /** Same-origin response for authenticated viewers and canvas downloads; OSS performs the resize. */
    public function displayResponse(string $disk, string $key, string $profile = 'preview', string $legacyCodec = 'plain'): Response
    {
        $image = $this->record($disk, $key);
        abort_if($image && $image->state !== 'ready', 404);
        $process = $image?->configuration_id ? ImagePresentation::process($profile, $image->mime) : null;
        $bytes = $process
            ? $this->oss->display(OssConfiguration::findOrFail($image->configuration_id), $image->object_key, $process)
            : $this->read($disk, $key, $legacyCodec);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'], true), 415);

        return response($bytes, 200, [
            'Content-Type' => $mime, 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline; filename=image', 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function ocrUrl(string $disk, string $key): string
    {
        $image = $this->record($disk, $key);
        if (! $image?->configuration_id && ! app()->environment('testing') && config('kyc.ocr_driver') !== 'mock') {
            throw new DomainException('KYC_DOCUMENT_STORAGE_FAILED', 'Configure and enable OSS before identity verification.', 503);
        }

        return $this->url($disk, $key);
    }

    // Call only for a staged object proven not to be referenced. Local migration backups are never deleted.
    public function discard(string $disk, string $key): void
    {
        $image = $this->record($disk, $key);
        if (! $image) {
            Storage::disk($disk)->delete($key);

            return;
        }
        $image->update(['state' => 'cleanup_pending', 'cleanup_after' => now()]);
        try {
            if ($image->configuration_id) {
                $this->oss->delete(OssConfiguration::findOrFail($image->configuration_id), $image->object_key);
            } elseif (! Storage::disk($disk)->delete($key)) {
                throw new \RuntimeException;
            }
            $image->update(['state' => 'deleted', 'last_error' => null]);
        } catch (\Throwable) {
            $image->update(['last_error' => 'DELETE_FAILED', 'cleanup_after' => now()->addMinute()]);
        }
    }

    public function decode(#[\SensitiveParameter] string $bytes, string $codec): string
    {
        return match ($codec) {
            'support' => Crypt::decryptString($bytes), 'card' => app(CardholderMaterials::class)->decrypt($bytes), default => $bytes
        };
    }

    private function encode(#[\SensitiveParameter] string $bytes, string $codec): string
    {
        return match ($codec) {
            'support' => Crypt::encryptString($bytes), 'card' => app(CardholderMaterials::class)->encrypt($bytes), default => $bytes
        };
    }
}
