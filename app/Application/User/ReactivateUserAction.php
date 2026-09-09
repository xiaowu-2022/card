<?php

namespace App\Application\User;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ReactivateUserAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(string $tenantId, string $userId, AdminUser $actor, ?string $requestId = null): void
    {
        DB::transaction(function () use ($tenantId, $userId, $actor, $requestId): void {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            if ($user->status !== UserStatus::Suspended) {
                throw new DomainException('USER_NOT_SUSPENDED', 'Only a SUSPENDED user can be reactivated.');
            }
            $user->update(['status' => UserStatus::Active]);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'USER_REACTIVATED', 'user', $user->id, ['status' => UserStatus::Suspended->value], ['status' => UserStatus::Active->value], $requestId);
        });
    }
}
