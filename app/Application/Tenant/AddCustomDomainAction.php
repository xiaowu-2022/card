<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Enums\TenantDomainType;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Domain\Tenant\Services\HostnameNormalizer;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class AddCustomDomainAction
{
    public function __construct(
        private HostnameNormalizer $hostnames,
        private AuditLogger $audit,
    ) {}

    public function execute(?Tenant $tenant, string $hostname, AdminUser $actor, ?string $requestId = null): TenantDomain
    {
        app(CompanyConfigurationAuthority::class)->assert($actor);
        $hostname = $this->hostnames->normalize($hostname);
        if (TenantDomain::query()->where('hostname', $hostname)->exists()) {
            throw new DomainException('DOMAIN_ALREADY_EXISTS', 'This hostname is already registered.');
        }

        return DB::transaction(function () use ($tenant, $hostname, $actor, $requestId): TenantDomain {
            if ($tenant !== null) {
                Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            }
            app(CompanyConfigurationAuthority::class)->assert($actor);
            $domain = TenantDomain::query()->create([
                'tenant_id' => $tenant?->id,
                'hostname' => $hostname,
                'domain_type' => TenantDomainType::CustomDomain,
                'status' => TenantDomainStatus::Active,
                'is_primary' => false,
                'ssl_status' => 'PENDING',
            ]);
            $this->audit->record($tenant?->id, 'ADMIN', $actor->id, 'DOMAIN_ADDED', 'tenant_domain', $domain->id, null, [
                'hostname' => $domain->hostname,
                'status' => $domain->status->value,
                'domain_type' => $domain->domain_type->value,
            ], $requestId);

            return $domain;
        });
    }
}
