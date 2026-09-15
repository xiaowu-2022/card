<?php

namespace App\Application\Support;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;

final readonly class SupportAccess
{
    public function __construct(private AuthorizationService $authorization) {}

    public function admin(string $tenantId, string $adminId): void
    {
        $admin = AdminUser::query()->whereKey($adminId)->firstOrFail();
        abort_unless($admin->status === AdminUserStatus::Active && $this->authorization->allows($admin, ScopeType::Tenant, $tenantId, 'support.manage'), 403);
    }
}
