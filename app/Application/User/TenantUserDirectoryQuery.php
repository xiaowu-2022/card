<?php

namespace App\Application\User;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\User\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class TenantUserDirectoryQuery
{
    public function paginate(string $tenantId): LengthAwarePaginator
    {
        return User::query()->where('tenant_id', $tenantId)->with('profile')->latest()->paginate(20)->through(fn (User $user): array => $this->summary($user));
    }

    public function detail(string $tenantId, string $userId): array
    {
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->with(['profile', 'preference'])->firstOrFail();

        return [
            'user' => $this->summary($user) + ['locale' => $user->preference?->locale],
            'audit' => AuditLog::query()->where('tenant_id', $tenantId)->where('resource_type', 'user')->where('resource_id', $user->id)->latest('created_at')->limit(30)->get()->map(fn (AuditLog $log): array => [
                'id' => $log->id, 'action' => $log->action, 'createdAt' => $log->created_at->toIso8601String(),
            ]),
        ];
    }

    private function summary(User $user): array
    {
        return [
            'id' => $user->id,
            'displayName' => $user->profile?->display_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status->value,
            'verifiedChannel' => $user->email_verified_at ? 'EMAIL' : 'PHONE',
            'createdAt' => $user->created_at->toIso8601String(),
            'lastLoginAt' => $user->last_login_at?->toIso8601String(),
        ];
    }
}
