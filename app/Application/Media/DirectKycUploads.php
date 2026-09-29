<?php

namespace App\Application\Media;

use App\Domain\Media\DirectImageUpload;
use App\Domain\Media\OssConfiguration;
use App\Domain\Media\StoredImage;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Infrastructure\Storage\OssImages;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DirectKycUploads
{
    public function __construct(private OssImages $oss, private ImageStorage $images) {}

    private function owner(string $tenant, string $user): void
    {
        abort_unless(Tenant::whereKey($tenant)->where('status', TenantStatus::Active)->exists()
            && User::whereKey($user)->where('tenant_id', $tenant)->where('status', UserStatus::Active)->exists(), 403);
    }

    public function authorize(string $tenant, string $user, string $field, string $mime): array
    {
        $this->owner($tenant, $user);
        abort_unless(in_array($field, ['front', 'back'], true)
            && in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 422);
        $config = $this->images->active();
        if (! $config) {
            throw new DomainException('IMAGE_STORAGE_UNAVAILABLE', 'Image storage is unavailable. Please try again.', 503);
        }
        $max = (int) config('kyc.document_max_mb') * 1024 * 1024;
        $upload = DB::transaction(function () use ($tenant, $user, $field, $mime, $config, $max) {
            User::whereKey($user)->where('tenant_id', $tenant)->lockForUpdate()->firstOrFail();
            abort_if(DirectImageUpload::where('tenant_id', $tenant)->where('user_id', $user)
                ->whereNull('claimed_at')->where('expires_at', '>', now())->count() >= 10, 429);
            $id = (string) Str::uuid();
            $image = StoredImage::create([
                'tenant_id' => $tenant, 'source_disk' => 'private', 'source_key' => "direct/{$tenant}/{$user}/{$id}",
                'configuration_id' => $config->id, 'object_key' => "images/{$tenant}/".Str::uuid(),
                'purpose' => 'kyc', 'codec' => 'plain', 'mime' => $mime,
                // The backend never downloads this object, so do not invent a checksum or size.
                'size' => null, 'sha256' => null, 'state' => 'uploading', 'cleanup_after' => now()->addDay(),
            ]);

            return DirectImageUpload::create([
                'id' => $id, 'tenant_id' => $tenant, 'user_id' => $user, 'purpose' => 'kyc', 'field' => $field,
                'upload_mode' => 'kyc_url', 'staging_image_id' => $image->id, 'image_id' => $image->id,
                'max_bytes' => $max, 'expires_at' => now()->addMinutes(15),
            ]);
        });
        $image = StoredImage::findOrFail($upload->image_id);

        return ['id' => $upload->id, 'imageUrl' => $this->oss->url($config, $image->object_key),
            'expiresAt' => now()->addMinutes(5)->toIso8601String()]
            + $this->oss->directUploadPolicy($config, $image->object_key, $mime, $max, publicRead: true);
    }

    public function resolve(string $tenant, string $user, string $id, string $field, string $url): DirectKycImage
    {
        $this->owner($tenant, $user);
        $upload = DirectImageUpload::whereKey($id)->where('tenant_id', $tenant)->where('user_id', $user)->firstOrFail();
        abort_unless($upload->upload_mode === 'kyc_url' && $upload->purpose === 'kyc' && $upload->field === $field, 422);
        abort_if($upload->expires_at->isPast(), 410);
        abort_if($upload->claimed_at !== null, 409);
        $image = StoredImage::findOrFail($upload->image_id);
        abort_unless($image->state === 'uploading', 409);
        $expected = $this->oss->url(OssConfiguration::findOrFail($image->configuration_id), $image->object_key);
        // Exact match rejects arbitrary URLs, alternate hosts, query strings and other users' objects.
        abort_unless(hash_equals($expected, $url), 422);

        return new DirectKycImage($id, $tenant, $user, $field, $expected);
    }

    public function claim(DirectKycImage $file, string $tenant, string $disk, string $key, string $purpose, ?string $reference): string
    {
        $this->owner($tenant, $file->userId);
        abort_unless($tenant === $file->tenantId && $purpose === 'kyc'
            && str_starts_with($key, "kyc/{$tenant}/{$file->userId}/") && ! str_contains($key, '..'), 422);

        return DB::transaction(function () use ($file, $tenant, $disk, $key, $reference) {
            $upload = DirectImageUpload::whereKey($file->id)->where('tenant_id', $tenant)
                ->where('user_id', $file->userId)->lockForUpdate()->firstOrFail();
            abort_unless($upload->upload_mode === 'kyc_url' && $upload->purpose === 'kyc' && $upload->field === $file->field, 422);
            abort_if($upload->claimed_at !== null, 409);
            abort_if($upload->expires_at->isPast(), 410);
            $image = StoredImage::whereKey($upload->image_id)->lockForUpdate()->firstOrFail();
            abort_unless($image->state === 'uploading'
                && hash_equals($this->oss->url(OssConfiguration::findOrFail($image->configuration_id), $image->object_key), $file->url), 422);
            $image->update(['source_disk' => $disk, 'source_key' => $key, 'business_reference' => $reference, 'state' => 'ready']);
            $upload->update(['claimed_at' => now()]);

            return $key;
        });
    }
}
