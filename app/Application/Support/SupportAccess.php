<?php

namespace App\Application\Support;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use Illuminate\Support\Facades\DB;

final readonly class SupportAccess
{
    public function __construct(private AuthorizationService $authorization) {}

    public function platform(string $adminId, string $permission = 'support.read'): void
    {
        $admin = AdminUser::query()->whereKey($adminId)->firstOrFail();
        abort_if(DB::table('support_agent_accounts')->where('admin_id', $adminId)->exists(), 403);
        abort_unless($admin->status === AdminUserStatus::Active
            && $this->authorization->allows($admin, ScopeType::Platform, null, 'support.read')
            && $this->authorization->allows($admin, ScopeType::Platform, null, $permission), 403);
    }

    public function admin(string $tenantId, string $adminId): void
    {
        $admin = AdminUser::query()->whereKey($adminId)->firstOrFail();
        abort_if(DB::table('support_agent_accounts')->where('admin_id', $adminId)->exists(), 403);
        abort_unless($admin->status === AdminUserStatus::Active && $this->authorization->allows($admin, ScopeType::Tenant, $tenantId, 'support.manage'), 403);
    }
}
