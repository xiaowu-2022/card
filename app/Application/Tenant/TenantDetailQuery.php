<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminInvitation;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Services\TenantOnboardingStatusService;

final readonly class TenantDetailQuery
{
    public function __construct(private TenantOnboardingStatusService $onboarding) {}

    /** @return array<string, mixed> */
    public function execute(string $tenantId): array
    {
        $tenant = Tenant::query()->with([
            'branding', 'locales', 'businessSettings', 'kycSettings', 'domains',
            'adminMemberships.adminUser', 'adminMemberships.role',
        ])->findOrFail($tenantId);
        $invitations = AdminInvitation::query()
            ->with('role')
            ->where('tenant_id', $tenant->id)
            ->latest()
            ->get()
            ->map(fn ($invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->name,
                'status' => $invitation->status->value,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ]);

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status->value,
            'defaultLocale' => $tenant->default_locale,
            'timezone' => $tenant->timezone,
            'defaultAsset' => $tenant->default_asset,
            'createdAt' => $tenant->created_at->toIso8601String(),
            'settings' => [
                'brandName' => $tenant->branding->brand_name,
                'primaryColor' => $tenant->branding->primary_color,
                'securityDepositAmount' => $tenant->businessSettings->required_security_deposit_amount,
                'securityDepositAsset' => $tenant->businessSettings->required_security_deposit_asset,
                'kycEnabled' => $tenant->kycSettings->enabled,
                'kycReviewMode' => $tenant->kycSettings->review_mode->value,
            ],
            'domains' => $tenant->domains->map(fn ($domain) => [
                'id' => $domain->id,
                'hostname' => $domain->hostname,
                'type' => $domain->domain_type->value,
                'status' => $domain->status->value,
                'primary' => $domain->is_primary,
            ]),
            'admins' => $tenant->adminMemberships->map(fn ($membership) => [
                'name' => $membership->adminUser->name,
                'email' => $membership->adminUser->email,
                'role' => $membership->role->name,
                'status' => $membership->status->value,
            ]),
            'invitations' => $invitations,
            'onboarding' => $this->onboarding->for($tenant),
        ];
    }
}
