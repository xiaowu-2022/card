<?php

namespace App\Domain\Tenant\Services;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Tenant\Enums\KycReviewMode;
use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Enums\TenantDomainType;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;

final class TenantOnboardingStatusService
{
    /** @return array{foundation_ready:bool,business_ready:bool,items:list<array{key:string,label:string,complete:bool,required:bool}>} */
    public function for(Tenant $tenant): array
    {
        $tenant->loadMissing(['branding', 'locales', 'businessSettings', 'domains']);
        $enabledLocales = $tenant->locales->where('enabled', true);
        $defaultLocales = $enabledLocales->where('is_default', true);
        $ownerActive = $tenant->adminMemberships()
            ->where('status', MembershipStatus::Active)
            ->whereHas('adminUser', fn ($query) => $query->where('status', AdminUserStatus::Active))
            ->whereHas('role', fn ($query) => $query
                ->where('scope_type', ScopeType::Tenant)
                ->where('name', 'TENANT_OWNER'))
            ->exists();

        $items = [
            ['key' => 'tenant', 'label' => 'Tenant created', 'complete' => $tenant->exists, 'required' => true],
            ['key' => 'owner', 'label' => 'Tenant Owner active', 'complete' => $ownerActive, 'required' => true],
            ['key' => 'branding', 'label' => 'Branding configured', 'complete' => filled($tenant->branding?->brand_name) && preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $tenant->branding?->primary_color) === 1, 'required' => true],
            ['key' => 'locale_enabled', 'label' => 'At least one locale enabled', 'complete' => $enabledLocales->isNotEmpty(), 'required' => true],
            ['key' => 'locale_default', 'label' => 'Default locale is enabled', 'complete' => $defaultLocales->count() === 1 && $defaultLocales->first()?->locale === $tenant->default_locale, 'required' => true],
            ['key' => 'kyc', 'label' => 'KYC settings configured', 'complete' => in_array(PlatformKycSetting::current()->review_mode, [KycReviewMode::Manual, KycReviewMode::Automatic], true), 'required' => true],
            ['key' => 'deposit', 'label' => 'Security deposit requirement configured', 'complete' => $tenant->businessSettings !== null, 'required' => true],
            ['key' => 'system_domain', 'label' => 'System domain active', 'complete' => $tenant->domains->contains(fn ($domain) => $domain->domain_type === TenantDomainType::SystemSubdomain && $domain->status === TenantDomainStatus::Active), 'required' => true],
            ['key' => 'provider', 'label' => 'Card Provider — coming in a later phase', 'complete' => false, 'required' => false],
            ['key' => 'product', 'label' => 'Card Product — coming in a later phase', 'complete' => false, 'required' => false],
        ];
        $foundationReady = collect($items)->where('required', true)->every(fn (array $item) => $item['complete']);

        return ['foundation_ready' => $foundationReady, 'business_ready' => false, 'items' => $items];
    }
}
