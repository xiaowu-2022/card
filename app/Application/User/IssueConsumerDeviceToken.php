<?php

namespace App\Application\User;

use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Infrastructure\Auth\ConsumerDeviceToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class IssueConsumerDeviceToken
{
    public function execute(string $tenantId, string $userId, #[\SensitiveParameter] string $password, string $device): array
    {
        return DB::transaction(function () use ($tenantId, $userId, $password, $device): array {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            if (! in_array($tenant->status, [TenantStatus::Active, TenantStatus::Suspended], true)
                || $user->status === UserStatus::Disabled || ! Hash::check($password, $user->password_hash)) {
                throw ValidationException::withMessages(['identifier' => 'Invalid credentials.']);
            }
            $secret = Str::random(64);
            $expires = now()->addDays(30);
            $token = ConsumerDeviceToken::query()->create([
                'tenant_id' => $tenantId, 'tokenable_type' => User::class, 'tokenable_id' => $userId,
                'name' => $device, 'token' => hash('sha256', $secret), 'abilities' => ['consumer'],
                'session_version' => $user->session_version, 'expires_at' => $expires,
            ]);

            return ['token' => $token->id.'|'.$secret, 'expiresAt' => $expires->toIso8601String()];
        });
    }
}
