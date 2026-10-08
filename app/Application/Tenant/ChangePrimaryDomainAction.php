<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\TenantDomain;
use App\Support\Errors\DomainException;

final readonly class ChangePrimaryDomainAction
{
    public function execute(string $tenantId, string $domainId, AdminUser $actor, ?string $requestId = null): void
    {
        app(CompanyConfigurationAuthority::class)->assert($actor);
        TenantDomain::query()->where('tenant_id', $tenantId)->whereKey($domainId)->firstOrFail();
        throw new DomainException('PRIMARY_DOMAIN_RETIRED', 'Domains have equal priority. Manage domain assignments instead.');
    }
}
