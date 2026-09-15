<?php

namespace App\Application\Tenant;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;

final class DomainConfigurationQuery
{
    public function companies(): array
    {
        return Tenant::query()->orderBy('name')->get(['id', 'name'])->map(fn ($tenant) => ['id' => $tenant->id, 'name' => $tenant->name])->all();
    }

    public function execute(?string $tenantId = null): array
    {
        $query = TenantDomain::query()->with('tenant:id,name');
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->orderByDesc('is_primary')->orderBy('hostname')->get()->map(fn ($domain) => [
            'id' => $domain->id,
            'hostname' => $domain->hostname,
            'type' => $domain->domain_type->value,
            'status' => $domain->status->value,
            'primary' => $domain->is_primary,
            'sslStatus' => $domain->ssl_status,
            'companyId' => $domain->tenant_id,
            'companyName' => $domain->tenant?->name,
        ])->all();
    }
}
