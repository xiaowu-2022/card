<?php

namespace App\Application\Admin;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminMembership;

final class PlatformAdminTeamQuery
{
    public function execute(): array
    {
        $members = AdminMembership::query()
            ->where('scope_type', ScopeType::Platform)->whereNull('scope_id')->with(['adminUser', 'role'])->latest()->orderBy('id')->paginate(20)->withQueryString();

        return ['roles' => ['PLATFORM_ADMIN', 'PLATFORM_AUDITOR'],
            'pagination' => ['previous' => $members->previousPageUrl(), 'next' => $members->nextPageUrl(), 'total' => $members->total()],
            'members' => $members->getCollection()->map(fn ($membership): array => [
                'id' => $membership->id, 'name' => $membership->adminUser->name, 'email' => $membership->adminUser->email,
                'role' => $membership->role->name, 'status' => $membership->status->value,
                'accountStatus' => $membership->adminUser->status->value, 'createdAt' => $membership->created_at->toIso8601String(),
            ])->all()];
    }
}
