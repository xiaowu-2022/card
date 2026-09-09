<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class SuspendTenantAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(string $tenantId, AdminUser $actor, ?string $requestId = null): void
    {
        DB::transaction(function () use ($tenantId, $actor, $requestId): void {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            if ($tenant->status !== TenantStatus::Active) {
                throw new DomainException('TENANT_NOT_ACTIVE', 'Only an ACTIVE Tenant can be suspended.');
            }

            $tenant->update(['status' => TenantStatus::Suspended, 'suspended_at' => now()]);
            $this->audit->record($tenant->id, 'ADMIN', $actor->id, 'TENANT_SUSPENDED', 'tenant', $tenant->id, ['status' => TenantStatus::Active->value], ['status' => TenantStatus::Suspended->value], $requestId);
        });
    }
}
