<?php

namespace App\Application\User;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Hash;

final readonly class ChangeUserPasswordAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(string $tenantId, string $userId, string $currentPassword, string $newPassword, ?string $requestId = null): void
    {
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        if (! Hash::check($currentPassword, $user->password_hash)) {
            throw new DomainException('CURRENT_PASSWORD_INVALID', 'The current password is incorrect.');
        }

        $user->forceFill(['password_hash' => Hash::make($newPassword)])->save();
        $this->audit->record($tenantId, 'USER', $user->id, 'USER_PASSWORD_CHANGED', 'user', $user->id, null, null, $requestId);
    }
}
