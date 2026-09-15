<?php

namespace App\Application\User;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserProfile;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class UpdateUserNameAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(string $tenantId, string $userId, string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new DomainException('INVALID_DISPLAY_NAME', 'Enter a name of 1 to 80 characters.');
        }
        DB::transaction(function () use ($tenantId, $userId, $name): void {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            if ($tenant->status !== TenantStatus::Active || $user->status !== UserStatus::Active) {
                throw new DomainException('ACCOUNT_RESTRICTED', 'Account changes are unavailable.', 403);
            }
            UserProfile::query()->updateOrCreate(['tenant_id' => $tenantId, 'user_id' => $userId], ['display_name' => $name]);
            $this->audit->record($tenantId, 'USER', $userId, 'USER_DISPLAY_NAME_CHANGED', 'user', $userId);
        });
    }
}
