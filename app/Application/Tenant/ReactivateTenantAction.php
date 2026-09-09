<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ReactivateTenantAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(string $tenantId, AdminUser $actor, ?string $requestId = null): void
    {
        DB::transaction(function () use ($tenantId, $actor, $requestId): void {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            if ($tenant->status !== TenantStatus::Suspended) {
                throw new DomainException('TENANT_NOT_SUSPENDED', 'Only a SUSPENDED Tenant can be reactivated.');
            }

            $tenant->update(['status' => TenantStatus::Active, 'suspended_at' => null]);
            $this->audit->record($tenant->id, 'ADMIN', $actor->id, 'TENANT_REACTIVATED', 'tenant', $tenant->id, ['status' => TenantStatus::Suspended->value], ['status' => TenantStatus::Active->value], $requestId);
        });
    }
}
