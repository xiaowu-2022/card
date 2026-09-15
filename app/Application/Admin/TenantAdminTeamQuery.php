<?php

namespace App\Application\Admin;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminInvitation;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Tenant\Models\Tenant;

final class TenantAdminTeamQuery
{
    /** @return array<string, mixed> */
    public function execute(Tenant $tenant): array
    {
        return [
            'members' => AdminMembership::query()
                ->with(['adminUser', 'role'])
                ->where('scope_type', ScopeType::Tenant)
                ->where('scope_id', $tenant->id)
                ->latest()
                ->get()
                ->map(fn ($membership) => [
                    'id' => $membership->id,
                    'name' => $membership->adminUser->name,
                    'email' => $membership->adminUser->email,
                    'role' => $membership->role->name,
                    'status' => $membership->status->value,
                    'accountStatus' => $membership->adminUser->status->value,
                ]),
            'invitations' => AdminInvitation::query()
                ->with('role')
                ->where('tenant_id', $tenant->id)
                ->latest()
                ->get()
                ->map(fn ($invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->name,
                    'status' => $invitation->status->value,
                    'expiresAt' => $invitation->expires_at->toIso8601String(),
                ]),
            'roles' => ['TENANT_ADMIN', 'KYC_REVIEWER', 'CARD_OPERATOR', 'FINANCE_VIEWER', 'SUPPORT'],
        ];
    }
}
