<?php

namespace App\Application\Tenant;

use App\Application\Media\ImageStorage;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class UpdateTenantBrandingAction
{
    public function __construct(private AuditLogger $audit) {}

    /** @param array{brand_name:string,primary_color:string,support_email:?string,support_url:?string,copyright_text:?string} $data */
    public function execute(Tenant $tenant, array $data, ?UploadedFile $logo, ?UploadedFile $favicon, AdminUser $actor, ?string $requestId = null, ?UploadedFile $apkLogo = null): void
    {
        app(CompanyConfigurationAuthority::class)->assert($actor);
        $images = app(ImageStorage::class);
        $newLogo = $logo ? $images->put($tenant->id, 'public', 'tenant-branding/'.$tenant->id.'/'.Str::uuid(), $logo->getContent(), 'branding', $tenant->id) : null;
        $newFavicon = null;
        $newApkLogo = null;
        try {
            $newFavicon = $favicon ? $images->put($tenant->id, 'public', 'tenant-branding/'.$tenant->id.'/'.Str::uuid(), $favicon->getContent(), 'branding', $tenant->id) : null;

            $newApkLogo = $apkLogo ? $images->put($tenant->id, 'public', 'tenant-branding/'.$tenant->id.'/'.Str::uuid(), $apkLogo->getContent(), 'branding', $tenant->id) : null;

            DB::transaction(function () use ($tenant, $data, $newLogo, $newFavicon, $newApkLogo, $actor, $requestId): void {
                $branding = $tenant->branding()->lockForUpdate()->firstOrFail();
                $before = $branding->only(['brand_name', 'apk_name', 'logo_object_key', 'favicon_object_key', 'apk_logo_object_key', 'primary_color', 'support_email', 'support_url', 'copyright_text']);
                $branding->update([
                    'brand_name' => $data['brand_name'],
                    'apk_name' => array_key_exists('apk_name', $data) ? $data['apk_name'] : $branding->apk_name,
                    'primary_color' => $data['primary_color'],
                    'support_email' => $data['support_email'],
                    'support_url' => $data['support_url'],
                    'copyright_text' => $data['copyright_text'],
                    'logo_object_key' => $newLogo ?? $branding->logo_object_key,
                    'apk_logo_object_key' => $newApkLogo ?? $branding->apk_logo_object_key,
                    'favicon_object_key' => $newFavicon ?? $branding->favicon_object_key,
                ]);
                $this->audit->record($tenant->id, 'ADMIN', $actor->id, 'TENANT_BRANDING_UPDATED', 'tenant_branding', $tenant->id, $before, $branding->fresh()->only(array_keys($before)), $requestId);
            });

        } catch (\Throwable $error) {
            $current = $tenant->branding()->first();
            foreach ([$newLogo, $newFavicon, $newApkLogo] as $key) {
                if ($key && ! in_array($key, [$current?->logo_object_key, $current?->favicon_object_key, $current?->apk_logo_object_key], true)) {
                    $images->discard('public', $key);
                }
            }
            throw $error;
        }
    }
}
