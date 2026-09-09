<?php

namespace App\Application\Admin;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;

final readonly class LogoutAdminAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(AdminUser $admin, ?string $tenantId, ?string $requestId, ?string $ipAddress, ?string $userAgent): void
    {
        $this->audit->record(
            $tenantId,
            'ADMIN',
            $admin->id,
            'ADMIN_LOGOUT',
            'admin_authentication',
            null,
            null,
            null,
            $requestId,
            $ipAddress,
            $userAgent,
        );
    }
}
