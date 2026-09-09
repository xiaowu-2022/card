<?php

namespace App\Application\User;

use App\Application\User\DTOs\CreatedRegistrationChallenge;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Notification\Contracts\EmailVerificationSender;
use App\Domain\Notification\Contracts\SmsVerificationSender;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\RegistrationChallengeStatus;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Models\RegistrationChallenge;
use App\Domain\User\Models\User;
use App\Domain\User\Services\ContactMasker;
use App\Domain\User\Services\EmailNormalizer;
use App\Domain\User\Services\OtpHasher;
use App\Domain\User\Services\PhoneNormalizer;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateRegistrationChallengeAction
{
    public function __construct(
        private EmailNormalizer $emails,
        private PhoneNormalizer $phones,
        private OtpHasher $hasher,
        private ContactMasker $masker,
        private EmailVerificationSender $emailSender,
        private SmsVerificationSender $smsSender,
        private AuditLogger $audit,
    ) {}

    public function execute(Tenant $tenant, RegistrationChannel $channel, string $destination, ?string $region = null, ?string $requestId = null): CreatedRegistrationChallenge
    {
        $destination = $channel === RegistrationChannel::Email
            ? $this->emails->normalize($destination)
            : $this->phones->normalize($destination, $region);
        $contactColumn = $channel === RegistrationChannel::Email ? 'email' : 'phone';
        $existing = User::query()->where('tenant_id', $tenant->id)->where($contactColumn, $destination)->exists();
        $id = (string) Str::uuid();
        $rawCode = (string) random_int(100000, 999999);

        try {
            $challenge = DB::transaction(function () use ($tenant, $channel, $destination, $id, $rawCode, $requestId): RegistrationChallenge {
                $recent = RegistrationChallenge::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('destination', $destination)
                    ->where('created_at', '>', now()->subSeconds((int) config('user-auth.resend_cooldown_seconds')))
                    ->lockForUpdate()
                    ->exists();
                if ($recent) {
                    throw new DomainException('REGISTRATION_SEND_COOLDOWN', 'Please wait before requesting another verification code.', 429);
                }

                RegistrationChallenge::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('destination', $destination)
                    ->where('status', RegistrationChallengeStatus::Pending)
                    ->lockForUpdate()
                    ->update(['status' => RegistrationChallengeStatus::Cancelled, 'cancelled_at' => now()]);

                $challenge = RegistrationChallenge::query()->create([
                    'id' => $id,
                    'tenant_id' => $tenant->id,
                    'channel' => $channel,
                    'destination' => $destination,
                    'code_hash' => $this->hasher->hash($id, $rawCode),
                    'status' => RegistrationChallengeStatus::Pending,
                    'attempt_count' => 0,
                    'expires_at' => now()->addMinutes((int) config('user-auth.otp_ttl_minutes')),
                    'cancelled_at' => null,
                ]);
                $this->audit->record($tenant->id, 'ANONYMOUS', null, 'REGISTRATION_CHALLENGE_CREATED', 'registration_challenge', $challenge->id, null, [
                    'channel' => $channel->value,
                    'destination' => $this->masker->mask($channel, $destination),
                ], $requestId);

                return $challenge;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw new DomainException('REGISTRATION_CHALLENGE_CONFLICT', 'Please wait before requesting another verification code.', 429);
            }

            throw $exception;
        }

        if ($channel === RegistrationChannel::Email) {
            $existing
                ? $this->emailSender->sendExistingAccountNotice($tenant, $destination)
                : $this->emailSender->sendVerificationCode($tenant, $destination, $rawCode);
        } else {
            $existing
                ? $this->smsSender->sendExistingAccountNotice($tenant, $destination)
                : $this->smsSender->sendVerificationCode($tenant, $destination, $rawCode);
        }

        return new CreatedRegistrationChallenge($challenge, $rawCode, $existing);
    }
}
