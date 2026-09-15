<?php

namespace App\Application\User;

use App\Application\User\DTOs\CreatedRegistrationChallenge;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Notification\Contracts\EmailVerificationSender;
use App\Domain\Notification\Contracts\SmsVerificationSender;
use App\Domain\Notification\Exceptions\EmailDeliveryUnknown;
use App\Domain\Notification\Exceptions\SmsDeliveryUnknown;
use App\Domain\Notification\Services\TenantSmsPolicy;
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
        private TenantSmsPolicy $smsPolicy,
        private UserVerificationEmailLimit $emailLimit,
        private AuditLogger $audit,
    ) {}

    /** @param list<string> $reusableChallengeIds */
    public function execute(Tenant $tenant, RegistrationChannel $channel, string $destination, ?string $region = null, ?string $requestId = null, array $reusableChallengeIds = [], ?string $promotionInviterId = null, ?string $companyInvitationId = null): CreatedRegistrationChallenge
    {
        if ($channel === RegistrationChannel::Email && ! $this->emailSender->isAvailable($tenant)) {
            throw new DomainException('EMAIL_VERIFICATION_UNAVAILABLE', 'Email verification is not available in this environment.', 503);
        }
        if ($channel === RegistrationChannel::Phone && ! $this->smsSender->isAvailable($tenant)) {
            throw new DomainException('PHONE_VERIFICATION_UNAVAILABLE', 'Phone verification is not available in this environment.', 503);
        }

        $destination = $channel === RegistrationChannel::Email
            ? $this->emails->normalize($destination)
            : $this->phones->normalize($destination, $region);
        $contactColumn = $channel === RegistrationChannel::Email ? 'email' : 'phone';
        $existing = User::query()->where('tenant_id', $tenant->id)->where($contactColumn, $destination)->exists();
        $id = (string) Str::uuid();
        $rawCode = (string) random_int(100000, 999999);
        $timing = $channel === RegistrationChannel::Phone
            ? $this->smsPolicy->timing($tenant->id)
            : ['resend' => (int) config('user-auth.resend_cooldown_seconds'), 'ttl' => 60 * (int) config('user-auth.otp_ttl_minutes')];

        try {
            [$challenge, $reusedVerified, $reusedDelivery] = DB::transaction(function () use ($tenant, $channel, $destination, $id, $rawCode, $requestId, $existing, $reusableChallengeIds, $timing, $promotionInviterId, $companyInvitationId): array {
                $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                DB::select('SELECT pg_advisory_xact_lock(?)', [$this->registrationLockKey($tenant->id, $channel, $destination)]);
                $pending = RegistrationChallenge::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('channel', $channel)
                    ->where('destination', $destination)
                    ->where('status', RegistrationChallengeStatus::Pending)
                    ->lockForUpdate()
                    ->get();

                foreach ($pending as $pendingChallenge) {
                    if ($pendingChallenge->expires_at->isPast()) {
                        $pendingChallenge->update(['status' => RegistrationChallengeStatus::Expired]);
                    } elseif ($pendingChallenge->sms_delivery_uncertain || $pendingChallenge->email_delivery_uncertain) {
                        if (in_array($pendingChallenge->id, $reusableChallengeIds, true)) {
                            $this->assertSameInviter($pendingChallenge, $promotionInviterId, $companyInvitationId);

                            return [$pendingChallenge, false, true];
                        }
                        throw new DomainException('REGISTRATION_SEND_COOLDOWN', 'Please wait before requesting another verification code.', 429);
                    }
                }

                if (! $existing && $reusableChallengeIds !== []) {
                    $verified = RegistrationChallenge::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('channel', $channel)
                        ->where('destination', $destination)
                        ->where('status', RegistrationChallengeStatus::Verified)
                        ->whereNull('consumed_at')
                        ->where('expires_at', '>', now())
                        ->whereIn('id', $reusableChallengeIds)
                        ->latest('verified_at')
                        ->lockForUpdate()
                        ->first();
                    if ($verified) {
                        $this->assertSameInviter($verified, $promotionInviterId, $companyInvitationId);

                        return [$verified, true, false];
                    }
                }

                $recent = RegistrationChallenge::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('channel', $channel)
                    ->where('destination', $destination)
                    ->where('status', RegistrationChallengeStatus::Pending)
                    ->where('created_at', '>', now()->subSeconds($timing['resend']))
                    ->lockForUpdate()
                    ->exists();
                if ($recent) {
                    throw new DomainException('REGISTRATION_SEND_COOLDOWN', 'Please wait before requesting another verification code.', 429);
                }

                if ($channel === RegistrationChannel::Email) {
                    $this->emailLimit->assertAvailable($tenant, $destination);
                }

                RegistrationChallenge::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('channel', $channel)
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
                    'expires_at' => now()->addSeconds($timing['ttl']),
                    'sms_delivery_uncertain' => $channel === RegistrationChannel::Phone,
                    'email_delivery_uncertain' => $channel === RegistrationChannel::Email,
                    'cancelled_at' => null,
                    'promotion_inviter_id' => $promotionInviterId,
                    'promotion_company_invitation_id' => $companyInvitationId,
                ]);
                $this->audit->record($tenant->id, 'ANONYMOUS', null, 'REGISTRATION_CHALLENGE_CREATED', 'registration_challenge', $challenge->id, null, [
                    'channel' => $channel->value,
                    'destination' => $this->masker->mask($channel, $destination),
                ], $requestId);

                return [$challenge, false, false];
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw new DomainException('REGISTRATION_CHALLENGE_CONFLICT', 'Please wait before requesting another verification code.', 429);
            }

            throw $exception;
        }

        if (! $reusedVerified && ! $reusedDelivery && $channel === RegistrationChannel::Email) {
            try {
                $existing
                    ? $this->emailSender->sendExistingAccountNotice($tenant, $destination)
                    : $this->emailSender->sendVerificationCode($tenant, $destination, $rawCode);
                $this->confirmEmailAttempt($tenant->id, $challenge->id);
                $challenge->email_delivery_uncertain = false;
            } catch (EmailDeliveryUnknown) {
                // SMTP may have accepted the message. Preserve its original challenge; never retry blindly.
            } catch (DomainException $exception) {
                $this->confirmEmailAttempt($tenant->id, $challenge->id);
                throw $exception;
            }
        } elseif (! $reusedVerified && ! $reusedDelivery) {
            try {
                $existing
                    ? $this->smsSender->sendExistingAccountNotice($tenant, $destination)
                    : $this->smsSender->sendVerificationCode($tenant, $destination, $rawCode);
                $this->confirmSmsAttempt($tenant->id, $challenge->id);
                $challenge->sms_delivery_uncertain = false;
            } catch (SmsDeliveryUnknown) {
                // Persisted before sending: even a process crash cannot trigger a blind resend.
            } catch (DomainException $exception) {
                $this->confirmSmsAttempt($tenant->id, $challenge->id);
                throw $exception;
            }
        }

        return new CreatedRegistrationChallenge($challenge, $reusedVerified || $reusedDelivery ? null : $rawCode, $existing, $reusedVerified, ! $reusedVerified && ($challenge->sms_delivery_uncertain || $challenge->email_delivery_uncertain));
    }

    private function confirmEmailAttempt(string $tenantId, string $challengeId): void
    {
        RegistrationChallenge::query()->where('tenant_id', $tenantId)->whereKey($challengeId)->update(['email_delivery_uncertain' => false]);
    }

    private function assertSameInviter(RegistrationChallenge $challenge, ?string $inviterId, ?string $companyId): void
    {
        if ($challenge->promotion_inviter_id !== $inviterId || $challenge->promotion_company_invitation_id !== $companyId) {
            throw new DomainException('INVITATION_IMMUTABLE', 'The invitation relationship cannot be changed.', 409);
        }
    }

    private function confirmSmsAttempt(string $tenantId, string $challengeId): void
    {
        RegistrationChallenge::query()->where('tenant_id', $tenantId)->whereKey($challengeId)
            ->update(['sms_delivery_uncertain' => false]);
    }

    private function registrationLockKey(string $tenantId, RegistrationChannel $channel, string $destination): int
    {
        return intval(substr(hash('sha256', $tenantId.'|'.$channel->value.'|'.$destination), 0, 15), 16);
    }
}
