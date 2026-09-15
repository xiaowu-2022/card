<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ActivateTenantDomainAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(?string $tenantId, string $domainId, AdminUser $actor, ?string $requestId = null): void
    {
        app(CompanyConfigurationAuthority::class)->assert($actor);
        DB::transaction(function () use ($tenantId, $domainId, $actor, $requestId): void {
            if ($tenantId !== null) {
                Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            }
            app(CompanyConfigurationAuthority::class)->assert($actor);
            $domain = TenantDomain::query()->where('tenant_id', $tenantId)->whereKey($domainId)->lockForUpdate()->firstOrFail();
            if ($domain->status !== TenantDomainStatus::Verified) {
                throw new DomainException('DOMAIN_NOT_VERIFIED', 'The domain must be verified before activation.');
            }

            $domain->update(['status' => TenantDomainStatus::Active, 'ssl_status' => 'PENDING']);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'DOMAIN_ACTIVATED', 'tenant_domain', $domain->id, ['status' => TenantDomainStatus::Verified->value], ['status' => TenantDomainStatus::Active->value, 'ssl_status' => 'PENDING'], $requestId);
        });
    }
}
