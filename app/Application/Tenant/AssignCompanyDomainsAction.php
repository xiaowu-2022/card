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

final readonly class AssignCompanyDomainsAction
{
    public function __construct(private CompanyConfigurationAuthority $authority, private AuditLogger $audit) {}

    public function execute(string $tenantId, array $selectedIds, array $originalIds, AdminUser $actor, ?string $requestId = null): void
    {
        $this->authority->assert($actor);
        DB::transaction(function () use ($tenantId, $selectedIds, $originalIds, $actor, $requestId): void {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $this->authority->assert($actor);
            $currentIds = TenantDomain::query()->where('tenant_id', $tenantId)->where('domain_type', TenantDomainType::CustomDomain)->pluck('id')->all();
            sort($currentIds);
            sort($originalIds);
            if ($currentIds !== $originalIds) {
                throw new DomainException('DOMAIN_ASSIGNMENT_CHANGED', 'Domain assignments changed. Reload and try again.');
            }
            $ids = array_values(array_unique(array_merge($currentIds, $selectedIds)));
            $domains = TenantDomain::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($selectedIds as $id) {
                $domain = $domains->get($id);
                if (! $domain || $domain->domain_type !== TenantDomainType::CustomDomain || ($domain->tenant_id !== null && $domain->tenant_id !== $tenantId)) {
                    throw new DomainException('DOMAIN_UNAVAILABLE', 'A selected domain is no longer available. Reload and try again.');
                }
                if ($domain->tenant_id === null && $domain->status !== TenantDomainStatus::Active) {
                    throw new DomainException('DOMAIN_NOT_ACTIVE', 'Only an ACTIVE domain can be assigned.');
                }
            }
            foreach (array_diff($currentIds, $selectedIds) as $id) {
                if ($domains[$id]->is_primary) {
                    throw new DomainException('PRIMARY_DOMAIN_DELETE_FORBIDDEN', 'Choose another primary domain before removing this assignment.');
                }
            }
            foreach (array_diff($currentIds, $selectedIds) as $id) {
                $domains[$id]->update(['tenant_id' => null]);
            }
            foreach (array_diff($selectedIds, $currentIds) as $id) {
                $domains[$id]->update(['tenant_id' => $tenantId]);
            }
            sort($selectedIds);
            if ($currentIds !== $selectedIds) {
                $this->audit->record($tenantId, 'ADMIN', $actor->id, 'COMPANY_DOMAINS_ASSIGNED', 'tenant', $tenantId, ['domain_ids' => $currentIds], ['domain_ids' => $selectedIds], $requestId);
            }
        });
    }
}
