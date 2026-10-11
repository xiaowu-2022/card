<?php

namespace App\Domain\Admin\Services;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AuthorizationService
{
    public function hasActiveMembership(AdminUser $admin, ScopeType $scopeType, ?string $scopeId): bool
    {
        if ($admin->status !== AdminUserStatus::Active
            || DB::table('support_agent_accounts')->where('admin_id', $admin->id)->exists()) {
            return false;
        }
        if (($scopeType === ScopeType::Platform && $scopeId !== null)
            || ($scopeType === ScopeType::Tenant && $scopeId === null)) {
            return false;
        }

        return $admin->memberships()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('status', MembershipStatus::Active)
            ->whereHas('role', fn ($query) => $query->where('scope_type', $scopeType)->where('name', '!=', 'SUPPORT_AGENT'))
            ->exists();
    }

    public function allows(AdminUser $admin, ScopeType $scopeType, ?string $scopeId, string $permission): bool
    {
        return $this->hasActiveMembership($admin, $scopeType, $scopeId)
            && $this->scopePermissions($scopeType)->where('permissions.name', $permission)->exists();
    }

    /** All active administrators share the complete permission catalog of their scope. */
    public function permissions(AdminUser $admin, ScopeType $scopeType, ?string $scopeId): Collection
    {
        return $this->hasActiveMembership($admin, $scopeType, $scopeId)
            ? $this->scopePermissions($scopeType)->distinct()->orderBy('permissions.name')->pluck('permissions.name')
            : collect();
    }

    private function scopePermissions(ScopeType $scopeType): Builder
    {
        return Permission::query()
            ->join('role_permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('roles.scope_type', $scopeType)
            ->where('roles.name', '!=', 'SUPPORT_AGENT');
    }
}
