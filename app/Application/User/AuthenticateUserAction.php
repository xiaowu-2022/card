<?php

namespace App\Application\User;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\User\Services\EmailNormalizer;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Hash;

final readonly class AuthenticateUserAction
{
    public function __construct(private EmailNormalizer $emails, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $identifier, string $password, ?string $region, ?string $requestId, ?string $ip, ?string $userAgent): ?User
    {
        try {
            $normalized = $this->emails->normalize($identifier);
        } catch (DomainException) {
            $normalized = strtolower(trim($identifier));
        }

        $user = User::query()->where('tenant_id', $tenantId)->where('email', $normalized)->first();
        $valid = $user instanceof User && $user->status !== UserStatus::Disabled && Hash::check($password, $user->password_hash);
        $this->audit->record($tenantId, $user ? 'USER' : 'ANONYMOUS', $user?->id, $valid ? 'USER_LOGIN_SUCCESS' : 'USER_LOGIN_FAILED', 'user_authentication', null, null, ['identifier_type' => 'email'], $requestId, $ip, $userAgent);
        if (! $valid) {
            return null;
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }
}
