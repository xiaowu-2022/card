<?php

namespace App\Domain\Tenant\Repositories;

use App\Domain\Tenant\Models\Tenant;

final class TenantRepository
{
    public function findForPlatformInspection(string $tenantId): ?Tenant
    {
        return Tenant::query()->find($tenantId);
    }
}
