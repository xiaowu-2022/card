<?php

namespace App\Application\User;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\User\Services\EmailNormalizer;
use App\Domain\User\Services\PhoneNormalizer;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Hash;

final readonly class AuthenticateUserAction
{
    public function __construct(private EmailNormalizer $emails, private PhoneNormalizer $phones, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $identifier, string $password, ?string $region, ?string $requestId, ?string $ip, ?string $userAgent): ?User
    {
        try {
            if (str_contains($identifier, '@')) {
                $column = 'email';
                $normalized = $this->emails->normalize($identifier);
            } else {
                $column = 'phone';
                $normalized = $this->phones->normalize($identifier, $region);
            }
        } catch (DomainException) {
            $column = 'email';
            $normalized = strtolower(trim($identifier));
        }

        $user = User::query()->where('tenant_id', $tenantId)->where($column, $normalized)->first();
        $valid = $user instanceof User && $user->status !== UserStatus::Disabled && Hash::check($password, $user->password_hash);
        $this->audit->record($tenantId, $user ? 'USER' : 'ANONYMOUS', $user?->id, $valid ? 'USER_LOGIN_SUCCESS' : 'USER_LOGIN_FAILED', 'user_authentication', null, null, ['identifier_type' => $column], $requestId, $ip, $userAgent);
        if (! $valid) {
            return null;
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }
}
