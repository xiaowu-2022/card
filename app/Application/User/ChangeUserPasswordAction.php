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

final readonly class ChangeUserPasswordAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(string $tenantId, string $userId, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword, ?string $requestId = null): int
    {
        return DB::transaction(function () use ($tenantId, $userId, $currentPassword, $newPassword, $requestId): int {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            if (! in_array($tenant->status, [TenantStatus::Active, TenantStatus::Suspended], true) || $user->status === UserStatus::Disabled) {
                throw new DomainException('ACCOUNT_RESTRICTED', 'Account changes are unavailable.', 403);
            }
            if (! Hash::check($currentPassword, $user->password_hash)) {
                throw new DomainException('CURRENT_PASSWORD_INVALID', 'The current password is incorrect.');
            }
            $version = $user->session_version + 1;
            $user->forceFill(['password_hash' => Hash::make($newPassword), 'session_version' => $version])->save();
            $this->audit->record($tenantId, 'USER', $user->id, 'USER_PASSWORD_CHANGED', 'user', $user->id, null, null, $requestId);

            return $version;
        });
    }
}
