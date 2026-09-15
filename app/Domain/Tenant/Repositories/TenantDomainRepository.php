<?php

namespace App\Domain\Tenant\Repositories;

use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Models\TenantDomain;

final class TenantDomainRepository
{
    public function resolveActiveHostname(string $hostname): ?TenantDomain
    {
        return TenantDomain::query()
            ->with(['tenant.branding', 'tenant.locales'])
            ->where('hostname', strtolower(rtrim($hostname, '.')))
            ->whereNotNull('tenant_id')
            ->where('status', TenantDomainStatus::Active)
            ->first();
    }

    public function findForTenant(?string $tenantId, string $domainId): ?TenantDomain
    {
        return TenantDomain::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($domainId)
            ->first();
    }
}
