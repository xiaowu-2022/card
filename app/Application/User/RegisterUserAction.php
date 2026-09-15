<?php

namespace App\Application\User;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\RegistrationChallengeStatus;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\RegistrationChallenge;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserPreference;
use App\Domain\User\Models\UserProfile;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final readonly class RegisterUserAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(Tenant $tenant, string $challengeId, string $password, ?string $displayName = null, ?string $locale = null, ?string $requestId = null): User
    {
        try {
            return DB::transaction(function () use ($tenant, $challengeId, $password, $displayName, $locale, $requestId): User {
                $currentTenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $challenge = RegistrationChallenge::query()->where('tenant_id', $tenant->id)->whereKey($challengeId)->lockForUpdate()->first();
                if (! $challenge || $challenge->status !== RegistrationChallengeStatus::Verified || $challenge->consumed_at !== null || $challenge->expires_at->isPast()) {
                    throw new DomainException('REGISTRATION_CHALLENGE_NOT_VERIFIED', 'A valid verified challenge is required.');
                }

                $email = $challenge->channel === RegistrationChannel::Email ? $challenge->destination : null;
                $phone = $challenge->channel === RegistrationChannel::Phone ? $challenge->destination : null;
                $user = User::query()->create([
                    'tenant_id' => $tenant->id,
                    'email' => $email,
                    'phone' => $phone,
                    'password_hash' => Hash::make($password),
                    'status' => UserStatus::Active,
                    'email_verified_at' => $email ? now() : null,
                    'phone_verified_at' => $phone ? now() : null,
                ]);
                UserProfile::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'display_name' => $displayName ? trim($displayName) : null]);
                $selectedLocale = $currentTenant->locales()->where('enabled', true)->where('locale', $locale)->exists()
                    ? $locale
                    : $currentTenant->locales()->where('enabled', true)->where('is_default', true)->value('locale');
                if (! is_string($selectedLocale)) {
                    throw new DomainException('TENANT_LOCALE_UNAVAILABLE', 'Registration is temporarily unavailable.');
                }
                UserPreference::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'locale' => $selectedLocale]);
                $challenge->update(['consumed_at' => now()]);
                $this->audit->record($tenant->id, 'USER', $user->id, 'USER_REGISTERED', 'user', $user->id, null, ['channel' => $challenge->channel->value], $requestId);

                // Load the database-assigned public ID; UUID relations stay intact.
                return User::query()->where('tenant_id', $tenant->id)->whereKey($user->id)->firstOrFail();
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === 'P2001') {
                throw new DomainException('ACCOUNT_ID_CAPACITY_EXHAUSTED', 'Registration is temporarily unavailable.');
            }
            if ($exception->getCode() === '23505') {
                throw new DomainException('ACCOUNT_ALREADY_EXISTS', 'An account already exists for this contact.');
            }

            throw $exception;
        }
    }
}
