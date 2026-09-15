<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Contracts\DomainVerificationService;
use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Domain\Tenant\Repositories\TenantDomainRepository;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class CheckDomainVerificationAction
{
    public function __construct(
        private TenantDomainRepository $domains,
        private DomainVerificationService $verifier,
        private AuditLogger $audit,
    ) {}

    public function execute(?string $tenantId, string $domainId, AdminUser $actor, ?string $requestId = null): bool
    {
        app(CompanyConfigurationAuthority::class)->assert($actor);
        $domain = $this->domains->findForTenant($tenantId, $domainId);
        if (! $domain || $domain->status !== TenantDomainStatus::PendingVerification) {
            throw new DomainException('DOMAIN_NOT_PENDING', 'Only a pending custom domain can be verified.');
        }

        $verified = $this->verifier->verify($domain->hostname, (string) $domain->verification_token);
        DB::transaction(function () use ($tenantId, $domainId, $verified, $actor, $requestId): void {
            if ($tenantId !== null) {
                Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            }
            app(CompanyConfigurationAuthority::class)->assert($actor);
            $domain = TenantDomain::query()->where('tenant_id', $tenantId)->whereKey($domainId)->lockForUpdate()->firstOrFail();
            if ($domain->status !== TenantDomainStatus::PendingVerification) {
                throw new DomainException('DOMAIN_NOT_PENDING', 'Only a pending custom domain can be verified.');
            }
            if ($verified) {
                $domain->update(['status' => TenantDomainStatus::Verified, 'verified_at' => now()]);
            }
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'DOMAIN_VERIFICATION_CHECKED', 'tenant_domain', $domain->id, null, [
                'hostname' => $domain->hostname,
                'verified' => $verified,
                'status' => $domain->status->value,
            ], $requestId);
        });

        return $verified;
    }
}
