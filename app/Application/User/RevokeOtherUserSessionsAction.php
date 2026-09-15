<?php

namespace App\Application\User;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final readonly class RevokeOtherUserSessionsAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(string $tenantId, string $userId, #[\SensitiveParameter] string $password): int
    {
        return DB::transaction(function () use ($tenantId, $userId, $password): int {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            if (! in_array($tenant->status, [TenantStatus::Active, TenantStatus::Suspended], true) || $user->status === UserStatus::Disabled) {
                throw new DomainException('ACCOUNT_RESTRICTED', 'Account changes are unavailable.', 403);
            }
            if (! Hash::check($password, $user->password_hash)) {
                throw new DomainException('CURRENT_PASSWORD_INVALID', 'The current password is incorrect.');
            }
            $version = $user->session_version + 1;
            $user->forceFill(['session_version' => $version])->save();
            $this->audit->record($tenantId, 'USER', $userId, 'USER_OTHER_SESSIONS_REVOKED', 'user', $userId);

            return $version;
        });
    }
}
