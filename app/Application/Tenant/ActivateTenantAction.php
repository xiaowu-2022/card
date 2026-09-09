<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Services\TenantOnboardingStatusService;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ActivateTenantAction
{
    public function __construct(
        private TenantOnboardingStatusService $onboarding,
        private AuditLogger $audit,
    ) {}

    public function execute(Tenant $tenant, AdminUser $actor, ?string $requestId = null): void
    {
        if ($tenant->status !== TenantStatus::Draft) {
            throw new DomainException('TENANT_NOT_DRAFT', 'Only a DRAFT Tenant can be activated.');
        }

        if (! $this->onboarding->for($tenant)['foundation_ready']) {
            throw new DomainException('TENANT_FOUNDATION_INCOMPLETE', 'Complete every required foundation item before activation.');
        }

        DB::transaction(function () use ($tenant, $actor, $requestId): void {
            $locked = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== TenantStatus::Draft) {
                throw new DomainException('TENANT_NOT_DRAFT', 'Only a DRAFT Tenant can be activated.');
            }

            $locked->update(['status' => TenantStatus::Active, 'activated_at' => now(), 'suspended_at' => null]);
            $this->audit->record($locked->id, 'ADMIN', $actor->id, 'TENANT_ACTIVATED', 'tenant', $locked->id, ['status' => TenantStatus::Draft->value], ['status' => TenantStatus::Active->value, 'readiness' => 'FOUNDATION_READY'], $requestId);
        });
    }
}
