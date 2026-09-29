<?php

namespace App\Application\Media;

use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Media\DirectImageUpload;
use App\Domain\Media\OssConfiguration;
use App\Domain\Media\StoredImage;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Infrastructure\Storage\OssImages;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DirectImageUploads
{
    public function __construct(private OssImages $oss, private ImageStorage $images) {}

    private function owner(string $tenantId, string $userId): void
    {
        abort_unless(Tenant::whereKey($tenantId)->where('status', TenantStatus::Active)->exists()
            && User::whereKey($userId)->where('tenant_id', $tenantId)->where('status', UserStatus::Active)->exists(), 403);
    }

    public function authorize(string $tenantId, string $userId, string $purpose, string $field, string $mime): array
    {
        $this->owner($tenantId, $userId);
        $max = match ($purpose) {
            'kyc' => (int) config('kyc.document_max_mb') * 1024 * 1024,
            'card' => 6 * 1024 * 1024,
            'support' => 5 * 1024 * 1024,
            default => throw new DomainException('IMAGE_INVALID', 'Use a supported image file.', 422),
        };
        abort_unless(in_array($field, $purpose === 'support' ? ['support_image'] : ['front', 'back'], true), 422);
        abort_unless(in_array($mime, $purpose === 'card' ? ['image/jpeg', 'image/png'] : ['image/jpeg', 'image/png', 'image/webp'], true), 422);
        if ($purpose === 'card') {
            abort_unless(app(KycStatusService::class)->forUser($tenantId, $userId) === KycUserStatus::Approved, 403);
        }
        $config = $this->images->active();
        if (! $config) {
            throw new DomainException('IMAGE_STORAGE_UNAVAILABLE', 'Image storage is unavailable. Please try again.', 503);
        }
        $upload = DB::transaction(function () use ($tenantId, $userId, $purpose, $field, $mime, $max, $config) {
            User::whereKey($userId)->where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();
            abort_if(DirectImageUpload::where('tenant_id', $tenantId)->where('user_id', $userId)->whereNull('claimed_at')->where('expires_at', '>', now())->count() >= 10, 429);
            $id = (string) Str::uuid();
            $common = ['tenant_id' => $tenantId, 'source_disk' => 'private', 'configuration_id' => $config->id,
                'codec' => 'plain', 'mime' => $mime, 'size' => 0, 'sha256' => str_repeat('0', 64), 'state' => 'uploading', 'cleanup_after' => now()->addDay()];
            $stage = StoredImage::create($common + ['source_key' => "direct-stage/{$tenantId}/{$id}", 'object_key' => "staging/{$tenantId}/{$id}", 'purpose' => 'direct-stage']);
            $image = StoredImage::create($common + ['source_key' => "direct/{$tenantId}/{$userId}/{$id}", 'object_key' => "images/{$tenantId}/".Str::uuid(), 'purpose' => $purpose]);

            return DirectImageUpload::create(['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'purpose' => $purpose, 'field' => $field,
                'staging_image_id' => $stage->id, 'image_id' => $image->id, 'max_bytes' => $max, 'expires_at' => now()->addMinutes(15)]);
        });
        $stage = StoredImage::findOrFail($upload->staging_image_id);

        // Signing is local; neither STS nor a successful server-to-OSS connection is required here.
        return ['id' => $upload->id, 'expiresAt' => now()->addMinutes(5)->toIso8601String()]
            + $this->oss->directUploadPolicy($config, $stage->object_key, $mime, $max);
    }

    private function scoped(string $tenant, string $user, string $id): DirectImageUpload
    {
        return DirectImageUpload::where('tenant_id', $tenant)->where('user_id', $user)->whereKey($id)->firstOrFail();
    }

    public function complete(string $tenant, string $user, string $id): void
    {
        $this->owner($tenant, $user);
        $upload = $this->scoped($tenant, $user, $id);
        $lock = Cache::lock('direct-image:'.$id, 180);
        abort_unless($lock->get(), 409);
        try {
            $upload->refresh();
            if ($upload->verified_at) {
                return;
            } // Idempotent: never recopy a signed staging object.
            abort_if($upload->expires_at->isPast(), 410);
            $stage = StoredImage::findOrFail($upload->staging_image_id);
            $image = StoredImage::findOrFail($upload->image_id);
            $config = OssConfiguration::findOrFail($image->configuration_id);
            $meta = $this->oss->metadata($config, $stage->object_key);
            abort_unless($meta['size'] > 0 && $meta['size'] <= $upload->max_bytes, 422);
            // A client can overwrite its staging key (even with versioning); it can never write the final key.
            // Copy is conditional on the metadata ETag. Repeated completion only reuses the verified final copy.
            $this->oss->copyDirectImage($config, $stage->object_key, $image->object_key, $meta['etag'], $image->mime);
            $bytes = $this->oss->getBounded($config, $image->object_key, $upload->max_bytes);
            $this->validateBytes($upload, $image, $bytes);
            $this->oss->publishDirectImage($config, $image->object_key);
            DB::transaction(function () use ($upload, $image, $bytes) {
                $current = DirectImageUpload::whereKey($upload->id)->lockForUpdate()->firstOrFail();
                if ($current->verified_at) {
                    return;
                }
                abort_if($current->expires_at->isPast(), 410);
                $image->update(['size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'state' => 'ready']);
                $current->update(['verified_at' => now()]);
            });
            // Staging is retained until its policy expires, then recovery deletes it. Never delete and
            // recreate a still-authorized key; this also avoids orphaned uploads after cleanup.
            $stage->update(['cleanup_after' => $upload->expires_at->addMinute()]);
        } finally {
            $lock->release();
        }
    }

    private function validateBytes(DirectImageUpload $upload, StoredImage $image, #[\SensitiveParameter] string $bytes): void
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $size = @getimagesizefromstring($bytes);
        if ($bytes === '' || strlen($bytes) > $upload->max_bytes || ! $size || $mime !== $image->mime || ($size['mime'] ?? '') !== $mime
            || ($upload->purpose === 'card' && ($size[0] > 12000 || $size[1] > 12000))
            || ($upload->purpose === 'support' && ($size[0] > 6000 || $size[1] > 6000 || $size[0] * $size[1] > 20000000))) {
            throw new DomainException('IMAGE_INVALID', 'Use a supported image file.', 422);
        }
    }

    public function resolve(string $tenant, string $user, string $id, string $purpose, string $field): VerifiedDirectImage
    {
        $this->owner($tenant, $user);
        $upload = $this->scoped($tenant, $user, $id);
        abort_unless($upload->purpose === $purpose && $upload->field === $field, 422);
        abort_unless($upload->verified_at, 409);
        // Claimed references remain readable for an existing business request replay.
        abort_if(! $upload->claimed_at && $upload->expires_at->isPast(), 410);
        $image = StoredImage::findOrFail($upload->image_id);
        abort_unless($image->state === 'ready', 409);
        $bytes = $this->oss->getBounded(OssConfiguration::findOrFail($image->configuration_id), $image->object_key, $upload->max_bytes);
        abort_unless(hash_equals($image->sha256, hash('sha256', $bytes)), 422);

        return new VerifiedDirectImage($id, $tenant, $user, $purpose, $field, $bytes, $image->mime);
    }

    public function claim(VerifiedDirectImage $file, string $tenant, string $disk, string $key, string $purpose, ?string $reference): string
    {
        abort_unless($file->tenantId === $tenant && $file->purpose === $purpose && str_contains($key, '/'.$tenant.'/') && ! str_contains($key, '..'), 422);

        return DB::transaction(function () use ($file, $tenant, $disk, $key, $purpose, $reference) {
            $upload = DirectImageUpload::whereKey($file->id)->where('tenant_id', $tenant)->where('user_id', $file->userId)->lockForUpdate()->firstOrFail();
            abort_unless($upload->verified_at && ! $upload->claimed_at && $upload->purpose === $purpose && $upload->field === $file->field, 409);
            abort_if($upload->expires_at->isPast(), 410);
            $image = StoredImage::whereKey($upload->image_id)->lockForUpdate()->firstOrFail();
            abort_unless($image->state === 'ready' && hash_equals($image->sha256, hash('sha256', $file->getContent())), 409);
            $image->update(['source_disk' => $disk, 'source_key' => $key, 'business_reference' => $reference]);
            $upload->update(['claimed_at' => now()]);

            return $key;
        });
    }
}
