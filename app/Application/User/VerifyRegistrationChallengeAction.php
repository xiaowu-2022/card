<?php

namespace App\Application\User;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\RegistrationChallengeStatus;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Models\RegistrationChallenge;
use App\Domain\User\Services\OtpHasher;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class VerifyRegistrationChallengeAction
{
    public function __construct(private OtpHasher $hasher, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $challengeId, string $code, ?string $requestId = null): RegistrationChallenge
    {
        [$challenge, $error] = DB::transaction(function () use ($tenantId, $challengeId, $code, $requestId): array {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $challenge = RegistrationChallenge::query()->where('tenant_id', $tenantId)->whereKey($challengeId)->lockForUpdate()->first();
            if (! $challenge || $challenge->channel !== RegistrationChannel::Email || $challenge->status !== RegistrationChallengeStatus::Pending) {
                return [$challenge, ['REGISTRATION_CHALLENGE_INVALID', 'This verification challenge is not available.', 422]];
            }
            if ($challenge->expires_at->isPast()) {
                $challenge->update(['status' => RegistrationChallengeStatus::Expired]);

                return [$challenge, ['REGISTRATION_CHALLENGE_EXPIRED', 'This verification code has expired.', 422]];
            }
            if (! $this->hasher->verify($challenge->id, $code, $challenge->code_hash)) {
                $attempts = $challenge->attempt_count + 1;
                $locked = $attempts >= (int) config('user-auth.otp_max_attempts');
                $challenge->update([
                    'attempt_count' => $attempts,
                    'status' => $locked ? RegistrationChallengeStatus::Locked : RegistrationChallengeStatus::Pending,
                    'locked_at' => $locked ? now() : null,
                ]);
                if ($locked) {
                    $this->audit->record($tenantId, 'ANONYMOUS', null, 'REGISTRATION_CHALLENGE_LOCKED', 'registration_challenge', $challenge->id, null, ['attempt_count' => $attempts], $requestId);
                }

                return [$challenge, ['REGISTRATION_CODE_INVALID', 'The verification code is invalid.', 422]];
            }

            RegistrationChallenge::query()
                ->where('tenant_id', $tenantId)
                ->where('channel', $challenge->channel)
                ->where('destination', $challenge->destination)
                ->where('status', RegistrationChallengeStatus::Verified)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->update(['expires_at' => now()]);
            $challenge->update(['status' => RegistrationChallengeStatus::Verified, 'verified_at' => now()]);
            $this->audit->record($tenantId, 'ANONYMOUS', null, 'REGISTRATION_CHALLENGE_VERIFIED', 'registration_challenge', $challenge->id, null, ['channel' => $challenge->channel->value], $requestId);

            return [$challenge, null];
        });

        if ($error !== null) {
            throw new DomainException($error[0], $error[1], $error[2]);
        }

        return $challenge;
    }
}
