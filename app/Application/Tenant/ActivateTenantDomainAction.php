<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Enums\TenantDomainType;
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
            if ($domain->domain_type !== TenantDomainType::CustomDomain || ! in_array($domain->status, [TenantDomainStatus::PendingVerification, TenantDomainStatus::Verified], true)) {
                throw new DomainException('DOMAIN_NOT_ACTIVATABLE', 'Only a pending custom domain can be activated.');
            }

            $before = $domain->status->value;
            $domain->update(['status' => TenantDomainStatus::Active]);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'DOMAIN_ACTIVATED', 'tenant_domain', $domain->id, ['status' => $before], ['status' => TenantDomainStatus::Active->value], $requestId);
        });
    }
}
