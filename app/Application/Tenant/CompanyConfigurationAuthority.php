<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;

final class CompanyConfigurationAuthority
{
    public function assert(AdminUser $actor): void
    {
        $actor = $actor->fresh();
        abort_unless($actor && $actor->status === AdminUserStatus::Active
            && app(AuthorizationService::class)->allows($actor, ScopeType::Platform, null, 'tenant.manage'), 403, 'Company configuration is managed by SaaS.');
    }
}
