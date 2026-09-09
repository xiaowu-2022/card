<?php

namespace App\Application\Admin;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use Illuminate\Support\Facades\Hash;

final readonly class AuthenticateAdminAction
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditLogger $audit,
    ) {}

    public function execute(
        string $email,
        string $password,
        ScopeType $scope,
        ?string $scopeId,
        ?string $requestId,
        ?string $ipAddress,
        ?string $userAgent,
    ): ?AdminUser {
        $admin = AdminUser::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();
        $valid = $admin instanceof AdminUser
            && $admin->status === AdminUserStatus::Active
            && Hash::check($password, $admin->password)
            && $this->authorization->hasActiveMembership($admin, $scope, $scopeId);

        $this->audit->record(
            $scopeId,
            $admin ? 'ADMIN' : 'ANONYMOUS',
            $admin?->id,
            $valid ? 'ADMIN_LOGIN_SUCCESS' : 'ADMIN_LOGIN_FAILED',
            'admin_authentication',
            null,
            null,
            ['scope' => $scope->value],
            $requestId,
            $ipAddress,
            $userAgent,
        );

        if (! $valid) {
            return null;
        }

        $admin->forceFill(['last_login_at' => now()])->save();

        return $admin;
    }
}
