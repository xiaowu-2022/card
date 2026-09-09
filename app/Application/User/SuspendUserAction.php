<?php

namespace App\Application\User;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class SuspendUserAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(string $tenantId, string $userId, AdminUser $actor, ?string $requestId = null): void
    {
        DB::transaction(function () use ($tenantId, $userId, $actor, $requestId): void {
            $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            if ($user->status !== UserStatus::Active) {
                throw new DomainException('USER_NOT_ACTIVE', 'Only an ACTIVE user can be suspended.');
            }
            $user->update(['status' => UserStatus::Suspended]);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'USER_SUSPENDED', 'user', $user->id, ['status' => UserStatus::Active->value], ['status' => UserStatus::Suspended->value], $requestId);
        });
    }
}
