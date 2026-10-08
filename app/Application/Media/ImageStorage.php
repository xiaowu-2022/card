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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

final class ImageStorage
{
    public function __construct(private OssImages $oss) {}

    public function active(): ?OssConfiguration
    {
        if (ServerImages::enabled()) {
            return null;
        }
        $id = DB::table('media_storage_settings')->where('id', 1)->value('active_configuration_id');

        return $id ? OssConfiguration::findOrFail($id) : null;
    }

    public function record(string $disk, string $key): ?StoredImage
    {
        return StoredImage::where('source_disk', $disk)->where('source_key', $key)->first();
    }

    public function put(string $tenant, string $disk, string $key, #[\SensitiveParameter] string $contents, string $purpose, ?string $reference = null, string $codec = 'plain', bool $deferOss = false): string
    {
        if (! Str::isUuid($tenant) || str_contains($key, '..') || ! str_contains($key, '/'.$tenant.'/')) {
            throw new \LogicException('Invalid image scope');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'], true)) {
            throw new DomainException('IMAGE_INVALID', 'Use a supported image file.', 422);
        }
        $config = $this->active();
        if (! $config && ! ServerImages::enabled() && ! $deferOss && ! app()->environment('testing')) {
            throw new DomainException('IMAGE_STORAGE_UNAVAILABLE', 'Image storage is unavailable. Please try again.', 503);
        }
        $image = StoredImage::create(['tenant_id' => $tenant, 'source_disk' => $disk, 'source_key' => $key, 'purpose' => $purpose, 'business_reference' => $reference,
            'configuration_id' => $config?->id, 'object_key' => $config ? 'images/'.$tenant.'/'.Str::uuid() : $key,
            'codec' => $config ? 'plain' : $codec, 'mime' => $mime, 'size' => strlen($contents), 'sha256' => hash('sha256', $contents), 'state' => 'uploading', 'cleanup_after' => now()->addDay()]);
        try {
            app(ImageReplicas::class)->save($image, $contents);
            if ($deferOss && ! ServerImages::enabled()) {
                $image->update(['oss_pending' => true, 'last_error' => 'OSS_UPLOAD_PENDING']);
            } elseif ($config) {
                try {
                    $this->oss->put($config, $image->object_key, $contents, $mime);
                } catch (\Throwable) {
                    $image->update(['oss_pending' => true, 'last_error' => 'OSS_UPLOAD_PENDING']);
                }
            } elseif (! ServerImages::enabled() && ! Storage::disk($disk)->put($key, $this->encode($contents, $codec))) {
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

    public function putUpload(string $tenant, string $disk, string $key, UploadedFile|VerifiedDirectImage|DirectKycImage $file, string $purpose, ?string $reference = null, string $codec = 'plain'): string
    {
        if ($file instanceof DirectKycImage) {
            return app(DirectKycUploads::class)->claim($file, $tenant, $disk, $key, $purpose, $reference);
        }
        if ($file instanceof VerifiedDirectImage) {
            return app(DirectImageUploads::class)->claim($file, $tenant, $disk, $key, $purpose, $reference);
        }

        return $this->put($tenant, $disk, $key, $file->getContent(), $purpose, $reference, $codec, $purpose === 'kyc');
    }

    public function read(string $disk, string $key, string $legacyCodec = 'plain'): string
    {
        $image = $this->record($disk, $key);
        if ($image && $image->state !== 'ready') {
            abort(404);
        }
        if ($image && (ServerImages::enabled() || $image->backup_key || $image->configuration_id)) {
            return $this->readImage($image);
        }
        $bytes = Storage::disk($disk)->get($key);
        abort_unless(is_string($bytes), 404);

        return $this->decode($bytes, $image?->codec ?? $legacyCodec);
    }

    public function readImage(StoredImage $image): string
    {
        if (ServerImages::enabled()) {
            return app(ServerImages::class)->read($image);
        }
        try {
            $config = $this->active();
            if (! $config || ($image->oss_pending && $image->configuration_id === $config->id)) {
                throw new \RuntimeException;
            }
            $bytes = $this->oss->getBounded($config, $image->object_key, max(1, $image->size ?? 20 * 1024 * 1024));
            if ($image->sha256 && ! hash_equals($image->sha256, hash('sha256', $bytes))) {
                throw new \RuntimeException;
            }

            return $bytes;
        } catch (\Throwable) {
            return app(ServerImages::class)->read($image);
        }
    }

    public function gatewayUrl(StoredImage $image, string $profile = 'original'): string
    {
        return URL::temporarySignedRoute('media.image', now()->addHours(12), ['image' => $image->id, 'profile' => $profile]);
    }

    public function url(string $disk, ?string $key): ?string
    {
        if ($published = $this->publishedBrandingUrl($disk, $key)) {
            return $published;
        }
        if (! $key) {
            return null;
        }
        $image = $this->record($disk, $key);
        if ($image && $image->state !== 'ready') {
            abort(404);
        }
        if ($image) {
            $config = $this->active();

            return ! $config
                ? $this->gatewayUrl($image)
                : $this->oss->url($config, $image->object_key);
        }

        return url(Storage::disk($disk)->url($key));
    }

    public function displayUrl(string $disk, ?string $key, string $profile = 'preview'): ?string
    {
        if ($published = $this->publishedBrandingUrl($disk, $key)) {
            return $published;
        }
        $url = $this->url($disk, $key);
        $image = $key ? $this->record($disk, $key) : null;
        if ($image) {
            if ($this->active()) {
                $process = $profile === 'original' ? null : ImagePresentation::process($profile, $image->mime);

                return $url.($process ? '?x-oss-process='.rawurlencode($process) : '');
            }

            return $this->gatewayUrl($image, $profile);
        }

        return $url;
    }

    /** Browser candidates only; URL generation never contacts storage. */
    public function previewSources(string $disk, ?string $key, string $profile = 'preview'): array
    {
        if ($published = $this->publishedBrandingUrl($disk, $key)) {
            return [$published];
        }
        if (! $key) {
            return [];
        }
        $image = $this->record($disk, $key);
        $sources = [$this->displayUrl($disk, $key, $profile)];
        if ($image && $this->active()) {
            $sources[] = $this->url($disk, $key);
            if ($image->backup_key) {
                $sources[] = URL::temporarySignedRoute('media.image', now()->addHours(12), [
                    'image' => $image->id, 'profile' => 'original', 'delivery' => 'replica',
                ]);
            }
        }

        return array_values(array_unique(array_filter($sources)));
    }

    private function publishedBrandingUrl(string $disk, ?string $key): ?string
    {
        if ($disk !== 'public' || ! $key || ! str_starts_with($key, 'tenant-branding/')) {
            return null;
        }
        $url = config('media.public_branding_urls', [])[$key] ?? null;

        return is_string($url) && preg_match('#^https://[a-z0-9.-]+/[^\s\\\\]+$#iD', $url) ? $url : null;
    }

    private function previewFailure(?StoredImage $image, string $stage, \Throwable $error): void
    {
        Log::warning('Image preview failed', [
            'request_id' => request()->attributes->get('request_id'),
            'image_id' => $image?->id, 'stage' => $stage,
            'reason' => $error instanceof DomainException ? $error->errorCode : 'read_or_validation_failed',
        ]);
    }

    /** Same-origin response for authenticated viewers and canvas downloads. */
    public function displayResponse(string $disk, string $key, string $profile = 'preview', string $legacyCodec = 'plain', bool $replicaOnly = false): Response
    {
        $image = $this->record($disk, $key);
        abort_if($image && $image->state !== 'ready', 404);
        $config = $this->active();
        $process = $config && $image && $profile !== 'original' ? ImagePresentation::process($profile, $image->mime) : null;
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'];
        $validate = static function (string $bytes) use ($allowed): array {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (! in_array($mime, $allowed, true)) {
                throw new \RuntimeException('Invalid preview media');
            }

            return [$bytes, $mime];
        };
        $original = null;
        $originalAttempted = false;
        $result = null;
        $integrityFailed = false;
        if (! $replicaOnly && $config && $image) {
            $readOriginal = function () use ($config, $image, &$original, &$originalAttempted, &$integrityFailed): string {
                if ($original === null) {
                    if ($originalAttempted) {
                        throw new \RuntimeException('Original read already failed');
                    }
                    $originalAttempted = true;
                    $original = $this->oss->getBounded($config, $image->object_key, max(1, $image->size ?? 20 * 1024 * 1024));
                    if ($image->sha256 && ! hash_equals($image->sha256, hash('sha256', $original))) {
                        $integrityFailed = true;
                        throw new \RuntimeException('Original checksum mismatch');
                    }
                }

                return $original;
            };
            if ($process) {
                try {
                    // Changed configurations must prove original identity before processing.
                    if ($image->configuration_id !== $config->id) {
                        if (! $image->sha256) {
                            $integrityFailed = true;
                            throw new \RuntimeException('Missing original checksum');
                        }
                        $readOriginal();
                    }
                    $result = $validate($this->oss->display($config, $image->object_key, $process));
                } catch (\Throwable $error) {
                    $this->previewFailure($image, 'processed', $error);
                }
            }
            if ($result === null && ! $integrityFailed) {
                try {
                    $result = $validate($readOriginal());
                } catch (\Throwable $error) {
                    $this->previewFailure($image, 'original', $error);
                }
            }
        }
        if ($result === null) {
            try {
                $bytes = $image?->backup_key
                    ? app(ImageReplicas::class)->read($image)
                    : (($replicaOnly || ($config && $image)) ? throw new \RuntimeException('Replica unavailable') : $this->read($disk, $key, $legacyCodec));
                $result = $validate($bytes);
            } catch (\Throwable $error) {
                $this->previewFailure($image, 'replica', $error);
                abort(503, 'Image could not be loaded. Please retry.');
            }
        }
        [$bytes, $mime] = $result;

        return response($bytes, 200, [
            'Content-Type' => $mime, 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline; filename=image', 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function ocrUrl(string $disk, string $key): string
    {
        $image = $this->record($disk, $key);
        if ($config = $this->active()) {
            abort_if($image && $image->state !== 'ready', 404);
            if (! $image || $image->oss_pending) {
                $reason = $image ? 'oss_replication_pending' : 'image_record_missing';
                Log::warning('KYC original unavailable', [
                    'request_id' => request()->attributes->get('request_id'),
                    'image_id' => $image?->id, 'reason' => $reason,
                ]);
                throw new DomainException('KYC_DOCUMENT_STORAGE_FAILED', 'Image storage is unavailable. Please try again.', 503, ['reason' => $reason]);
            }

            // The selected OCR service requires HTTP for OSS originals. Display/upload
            // URLs retain their configured scheme; signed server URLs are not rewritten.
            $url = $this->oss->url($config, $image->object_key);

            return preg_replace('/^https:\/\//i', 'http://', $url);
        }
        if (! ServerImages::enabled() && ! $image?->configuration_id && ! $image?->backup_key && ! app()->environment('testing') && config('kyc.ocr_driver') !== 'mock') {
            throw new DomainException('KYC_DOCUMENT_STORAGE_FAILED', 'Configure and enable OSS before identity verification.', 503);
        }

        return $this->url($disk, $key);
    }

    public function deferDiscard(string $disk, string $key): void
    {
        // No OSS request in the KYC response path. Wait past the upload policy before cleanup.
        $this->record($disk, $key)?->update(['state' => 'cleanup_pending', 'cleanup_after' => now()->addMinutes(16)]);
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
            if ($image->configuration_id && ! ServerImages::enabled()) {
                $this->oss->delete(OssConfiguration::findOrFail($image->configuration_id), $image->object_key);
            } elseif (! $image->configuration_id && Storage::disk($disk)->exists($key) && ! Storage::disk($disk)->delete($key)) {
                throw new \RuntimeException;
            }
            if ($image->backup_key) {
                Storage::disk('private')->delete($image->backup_key);
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
