<?php

namespace App\Domain\Admin\Services;

use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;

final class AuthorizationService
{
    public function allows(AdminUser $admin, ScopeType $scopeType, ?string $scopeId, string $permission): bool
    {
        if ($scopeType === ScopeType::Platform && $scopeId !== null) {
            return false;
        }

        if ($scopeType === ScopeType::Tenant && $scopeId === null) {
            return false;
        }

        return $admin->memberships()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('status', MembershipStatus::Active)
            ->whereHas('role', fn ($query) => $query
                ->where('scope_type', $scopeType)
                ->whereHas('permissions', fn ($permissions) => $permissions->where('name', $permission)))
            ->exists();
    }
}
