<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class UpdateTenantBrandingAction
{
    public function __construct(private AuditLogger $audit) {}

    /** @param array{brand_name:string,primary_color:string,support_email:?string,support_url:?string,copyright_text:?string} $data */
    public function execute(Tenant $tenant, array $data, ?UploadedFile $logo, ?UploadedFile $favicon, AdminUser $actor, ?string $requestId = null): void
    {
        app(CompanyConfigurationAuthority::class)->assert($actor);
        $newLogo = $logo?->store('tenant-branding/'.$tenant->id, 'public');
        $newFavicon = $favicon?->store('tenant-branding/'.$tenant->id, 'public');
        $oldLogo = $tenant->branding?->logo_object_key;
        $oldFavicon = $tenant->branding?->favicon_object_key;

        DB::transaction(function () use ($tenant, $data, $newLogo, $newFavicon, $actor, $requestId): void {
            $branding = $tenant->branding()->lockForUpdate()->firstOrFail();
            $before = $branding->only(['brand_name', 'logo_object_key', 'favicon_object_key', 'primary_color', 'support_email', 'support_url', 'copyright_text']);
            $branding->update([
                'brand_name' => $data['brand_name'],
                'primary_color' => $data['primary_color'],
                'support_email' => $data['support_email'],
                'support_url' => $data['support_url'],
                'copyright_text' => $data['copyright_text'],
                'logo_object_key' => $newLogo ?? $branding->logo_object_key,
                'favicon_object_key' => $newFavicon ?? $branding->favicon_object_key,
            ]);
            $this->audit->record($tenant->id, 'ADMIN', $actor->id, 'TENANT_BRANDING_UPDATED', 'tenant_branding', $tenant->id, $before, $branding->fresh()->only(array_keys($before)), $requestId);
        });

        if ($newLogo && $oldLogo) {
            Storage::disk('public')->delete($oldLogo);
        }
        if ($newFavicon && $oldFavicon) {
            Storage::disk('public')->delete($oldFavicon);
        }
    }
}
