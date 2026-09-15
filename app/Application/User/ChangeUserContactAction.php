<?php

namespace App\Application\User;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Notification\Contracts\EmailVerificationSender;
use App\Domain\Notification\Contracts\SmsVerificationSender;
use App\Domain\Notification\Exceptions\EmailDeliveryUnknown;
use App\Domain\Notification\Exceptions\SmsDeliveryUnknown;
use App\Domain\Notification\Services\TenantSmsPolicy;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserContactChange;
use App\Domain\User\Services\EmailNormalizer;
use App\Domain\User\Services\OtpHasher;
use App\Domain\User\Services\PhoneNormalizer;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final readonly class ChangeUserContactAction
{
    public function __construct(private EmailNormalizer $emails, private PhoneNormalizer $phones,
        private OtpHasher $hasher, private EmailVerificationSender $emailSender,
        private SmsVerificationSender $smsSender, private TenantSmsPolicy $smsPolicy,
        private UserVerificationEmailLimit $emailLimit, private AuditLogger $audit) {}

    public function start(string $tenantId, string $userId, RegistrationChannel $channel,
        #[\SensitiveParameter] string $destination, #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] string $sessionBinding, string $requestId): UserContactChange
    {
        $destination = $channel === RegistrationChannel::Email ? $this->emails->normalize($destination) : $this->phones->normalize($destination);
        $column = $channel === RegistrationChannel::Email ? 'email' : 'phone';
        $rawCode = (string) random_int(100000, 999999);
        [$change, $send, $tenant] = DB::transaction(function () use ($tenantId, $userId, $channel, $destination, $column, $password, $sessionBinding, $requestId, $rawCode): array {
            [$tenant, $user] = $this->lockOwner($tenantId, $userId);
            if (! Hash::check($password, $user->password_hash)) {
                throw new DomainException('CURRENT_PASSWORD_INVALID', 'The current password is incorrect.');
            }
            $existing = UserContactChange::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('request_id', $requestId)->first();
            if ($existing) {
                if ($existing->channel !== $channel->value || ! hash_equals($existing->destination_hash, $this->digest($tenantId.'|'.$channel->value.'|'.$destination)) || ! hash_equals($existing->session_hash, $this->digest($sessionBinding))) {
                    throw new DomainException('CONTACT_REQUEST_CONFLICT', 'Start a new contact change request.', 409);
                }
                if ($existing->cancelled_at || $existing->consumed_at || $existing->expires_at->isPast()
                    || $existing->attempt_count >= (int) config('user-auth.otp_max_attempts')
                    || ! hash_equals($existing->credential_hash, $this->credentialDigest($user, $column))) {
                    throw new DomainException('CONTACT_REQUEST_EXPIRED', 'Start a new contact change request.', 409);
                }

                return [$existing, false, $tenant];
            }
            $this->assertAvailableContact($tenantId, $column, $destination);
            $sender = $channel === RegistrationChannel::Email ? $this->emailSender : $this->smsSender;
            if (! $sender->isAvailable($tenant)) {
                throw new DomainException('CONTACT_DELIVERY_UNAVAILABLE', 'Verification delivery is unavailable. Please contact support.', 503);
            }
            $timing = $channel === RegistrationChannel::Phone ? $this->smsPolicy->timing($tenantId)
                : ['resend' => (int) config('user-auth.resend_cooldown_seconds'), 'ttl' => 60 * (int) config('user-auth.otp_ttl_minutes')];
            $hash = $this->digest($tenantId.'|'.$channel->value.'|'.$destination);
            $recent = UserContactChange::query()->where('tenant_id', $tenantId)
                ->where(fn ($q) => $q->where('user_id', $userId)->orWhere('destination_hash', $hash));
            if ((clone $recent)->where('created_at', '>', now()->subHour())->count() >= (int) config('user-auth.send_limit_per_hour')
                || (clone $recent)->where('created_at', '>', now()->subSeconds($timing['resend']))->exists()
                || (clone $recent)->where('delivery_uncertain', true)->whereNull('cancelled_at')->whereNull('consumed_at')->where('expires_at', '>', now())->exists()) {
                throw new DomainException('CONTACT_SEND_COOLDOWN', 'Please wait before requesting another verification code.', 429);
            }
            if ($channel === RegistrationChannel::Email) {
                $this->emailLimit->assertAvailable($tenant, $destination);
            }
            UserContactChange::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('channel', $channel->value)
                ->whereNull('consumed_at')->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
            $id = (string) Str::uuid();
            $change = UserContactChange::query()->create([
                'id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'request_id' => $requestId,
                'channel' => $channel->value, 'destination' => $destination, 'destination_hash' => $hash,
                'session_hash' => $this->digest($sessionBinding), 'credential_hash' => $this->credentialDigest($user, $column),
                'code_hash' => $this->hasher->hash($id, $rawCode), 'expires_at' => now()->addSeconds($timing['ttl']),
                'delivery_uncertain' => true,
            ]);
            $this->audit->record($tenantId, 'USER', $userId, 'USER_CONTACT_CHANGE_REQUESTED', 'user_contact_change', $id, null, ['channel' => $channel->value]);

            return [$change, true, $tenant];
        });
        // The intent is durable before transport; never send while holding ownership locks.
        if ($send) {
            try {
                ($channel === RegistrationChannel::Email ? $this->emailSender : $this->smsSender)->sendVerificationCode($tenant, $destination, $rawCode);
                $change->update(['delivery_uncertain' => false]);
            } catch (EmailDeliveryUnknown|SmsDeliveryUnknown) {
                // May already be delivered. Accept its original code; do not blindly resend.
            } catch (DomainException $exception) {
                $change->update(['delivery_uncertain' => false, 'cancelled_at' => now()]);
                throw $exception;
            }
        }

        return $change;
    }

    public function complete(string $tenantId, string $userId, string $changeId,
        #[\SensitiveParameter] string $sessionBinding, #[\SensitiveParameter] string $code): void
    {
        try {
            $error = DB::transaction(function () use ($tenantId, $userId, $changeId, $sessionBinding, $code): ?string {
                [, $user] = $this->lockOwner($tenantId, $userId);
                $change = UserContactChange::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($changeId)->lockForUpdate()->firstOrFail();
                if (! hash_equals($change->session_hash, $this->digest($sessionBinding))) {
                    return 'This verification request is no longer valid. Request a new code.';
                }
                if ($change->consumed_at) {
                    return null; // Idempotent acknowledgement only; never re-apply an old contact.
                }
                $column = $change->channel === 'EMAIL' ? 'email' : 'phone';
                if ($change->cancelled_at || $change->expires_at->isPast() || $change->attempt_count >= (int) config('user-auth.otp_max_attempts')
                    || ! hash_equals($change->credential_hash, $this->credentialDigest($user, $column))) {
                    return 'This verification request is no longer valid. Request a new code.';
                }
                if (! $this->hasher->verify($change->id, $code, $change->code_hash)) {
                    $change->increment('attempt_count');

                    return 'The verification code is incorrect.';
                }
                $this->assertAvailableContact($tenantId, $column, $change->destination);
                $user->forceFill([$column => $change->destination, $column.'_verified_at' => now()])->save();
                $change->update(['consumed_at' => now()]);
                $this->audit->record($tenantId, 'USER', $userId, 'USER_CONTACT_CHANGED', 'user_contact_change', $change->id, null, ['channel' => $change->channel]);

                return null;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw new DomainException('CONTACT_UNAVAILABLE', 'This contact is already in use. Choose another contact.', 409);
            }
            throw $exception;
        }
        if ($error !== null) {
            throw new DomainException('CONTACT_VERIFICATION_FAILED', $error);
        }
    }

    private function lockOwner(string $tenantId, string $userId): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
        if ($tenant->status !== TenantStatus::Active || $user->status !== UserStatus::Active) {
            throw new DomainException('ACCOUNT_RESTRICTED', 'Account changes are unavailable.', 403);
        }

        return [$tenant, $user];
    }

    private function assertAvailableContact(string $tenantId, string $column, #[\SensitiveParameter] string $destination): void
    {
        if (User::query()->where('tenant_id', $tenantId)->where($column, $destination)->exists()) {
            throw new DomainException('CONTACT_UNAVAILABLE', 'This contact is already in use. Choose another contact.', 409);
        }
    }

    private function credentialDigest(User $user, string $column): string
    {
        return $this->digest($user->id.'|'.$user->password_hash.'|'.$column.'|'.$user->{$column});
    }

    public function digest(#[\SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('user-auth.otp_secret'));
    }
}
