<?php

namespace App\Application\Admin;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class UpdateTenantAdminMembershipAction
{
    public function __construct(private AuthorizationService $authorization, private AuditLogger $audit) {}

    public function execute(Tenant $tenant, string $membershipId, AdminUser $actor, string $roleName, string $status, string $currentPassword, ?string $requestId = null): void
    {
        Validator::make(['role' => $roleName, 'status' => $status], [
            'role' => ['required', Rule::in(['TENANT_ADMIN', 'KYC_REVIEWER', 'CARD_OPERATOR', 'FINANCE_VIEWER', 'SUPPORT'])],
            'status' => ['required', Rule::in(['ACTIVE', 'SUSPENDED'])],
        ])->validate();

        DB::transaction(function () use ($tenant, $membershipId, $actor, $roleName, $status, $currentPassword, $requestId): void {
            $company = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $currentActor = AdminUser::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $currentActor->memberships()->where('scope_type', ScopeType::Platform)->whereNull('scope_id')->lockForUpdate()->get();
            if ($company->status === TenantStatus::Closed || $currentActor->status !== AdminUserStatus::Active
                || ! $this->authorization->allows($currentActor, ScopeType::Platform, null, 'tenant.manage')
                || ! Hash::check($currentPassword, $currentActor->password)) {
                throw new DomainException('ADMIN_MEMBERSHIP_UPDATE_FORBIDDEN', 'Administrator access could not be confirmed.', 403);
            }
            $membership = AdminMembership::query()->where('scope_type', ScopeType::Tenant)
                ->where('scope_id', $company->id)->whereKey($membershipId)->lockForUpdate()->firstOrFail();
            if ($membership->role->name === 'TENANT_OWNER' || $membership->status->value === 'REVOKED') {
                throw new DomainException('ADMIN_MEMBERSHIP_PROTECTED', 'This membership cannot be edited.', 403);
            }
            $role = Role::query()->where('scope_type', ScopeType::Tenant)->where('name', $roleName)->firstOrFail();
            if ($membership->role_id === $role->id && $membership->status->value === $status) {
                return;
            }
            $before = ['role' => $membership->role->name, 'status' => $membership->status->value];
            $membership->update(['role_id' => $role->id, 'status' => $status]);
            $this->audit->record($company->id, 'ADMIN', $currentActor->id, 'ADMIN_MEMBERSHIP_UPDATED', 'admin_membership', $membership->id,
                $before, ['role' => $role->name, 'status' => $status], $requestId);
        });
    }
}
