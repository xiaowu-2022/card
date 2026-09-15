<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantDomainType;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class DeleteTenantDomainAction
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
            if ($domain->domain_type === TenantDomainType::SystemSubdomain) {
                throw new DomainException('SYSTEM_DOMAIN_IMMUTABLE', 'The system domain cannot be deleted.');
            }
            if ($domain->is_primary) {
                throw new DomainException('PRIMARY_DOMAIN_DELETE_FORBIDDEN', 'Choose another primary domain before deleting this domain.');
            }

            $snapshot = ['hostname' => $domain->hostname, 'status' => $domain->status->value];
            $domain->delete();
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'DOMAIN_REMOVED', 'tenant_domain', $domainId, $snapshot, null, $requestId);
        });
    }
}
