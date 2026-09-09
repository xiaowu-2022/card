<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Models\TenantDomain;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ChangePrimaryDomainAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(string $tenantId, string $domainId, AdminUser $actor, ?string $requestId = null): void
    {
        DB::transaction(function () use ($tenantId, $domainId, $actor, $requestId): void {
            $selected = TenantDomain::query()->where('tenant_id', $tenantId)->whereKey($domainId)->lockForUpdate()->firstOrFail();
            if ($selected->status !== TenantDomainStatus::Active) {
                throw new DomainException('DOMAIN_NOT_ACTIVE', 'Only an ACTIVE domain can be primary.');
            }

            $previous = TenantDomain::query()->where('tenant_id', $tenantId)->where('is_primary', true)->lockForUpdate()->first();
            TenantDomain::query()->where('tenant_id', $tenantId)->where('is_primary', true)->update(['is_primary' => false]);
            $selected->update(['is_primary' => true]);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'PRIMARY_DOMAIN_CHANGED', 'tenant_domain', $selected->id, ['domain_id' => $previous?->id, 'hostname' => $previous?->hostname], ['domain_id' => $selected->id, 'hostname' => $selected->hostname], $requestId);
        });
    }
}
