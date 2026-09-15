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
use App\Domain\User\Models\UserPasswordReset;
use App\Domain\User\Services\EmailNormalizer;
use App\Domain\User\Services\OtpHasher;
use App\Domain\User\Services\PhoneNormalizer;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final readonly class ResetForgottenUserPasswordAction
{
    private const INVALID = 'This password reset request is invalid or expired. Request a new code.';

    public function __construct(private EmailNormalizer $emails, private PhoneNormalizer $phones,
        private OtpHasher $hasher, private EmailVerificationSender $emailSender,
        private SmsVerificationSender $smsSender, private UserVerificationEmailLimit $emailLimit,
        private TenantSmsPolicy $smsPolicy, private AuditLogger $audit) {}

    public function start(string $tenantId, RegistrationChannel $channel, #[\SensitiveParameter] string $destination,
        #[\SensitiveParameter] string $binding, #[\SensitiveParameter] string $ip, string $requestId): UserPasswordReset
    {
        $destination = $channel === RegistrationChannel::Email ? $this->emails->normalize($destination) : $this->phones->normalize($destination);
        $code = (string) random_int(100000, 999999);
        [$reset, $send, $tenant] = DB::transaction(function () use ($tenantId, $channel, $destination, $binding, $ip, $requestId, $code): array {
            $tenant = $this->lockTenant($tenantId);
            $hash = $this->digest($tenantId.'|'.$channel->value.'|'.$destination);
            $sessionHash = $this->digest($binding);
            $ipHash = $this->digest($tenantId.'|'.$ip);
            $existing = UserPasswordReset::query()->where('tenant_id', $tenantId)->where('request_id', $requestId)->first();
            if ($existing) {
                if (! hash_equals($existing->session_hash, $sessionHash) || ! hash_equals($existing->destination_hash, $hash)
                    || $existing->channel !== $channel->value || $existing->cancelled_at || $existing->consumed_at
                    || $existing->expires_at->isPast() || $existing->attempt_count >= (int) config('user-auth.otp_max_attempts')) {
                    throw new DomainException('PASSWORD_RESET_INVALID', self::INVALID);
                }

                return [$existing, false, $tenant];
            }
            $sender = $channel === RegistrationChannel::Email ? $this->emailSender : $this->smsSender;
            if (! $sender->isAvailable($tenant)) {
                throw new DomainException('PASSWORD_RESET_DELIVERY_UNAVAILABLE', 'Verification delivery is unavailable. Please contact support.', 503);
            }
            $timing = $channel === RegistrationChannel::Phone ? $this->smsPolicy->timing($tenantId)
                : ['resend' => (int) config('user-auth.resend_cooldown_seconds'), 'ttl' => 60 * (int) config('user-auth.otp_ttl_minutes')];
            $recipient = UserPasswordReset::query()->where('tenant_id', $tenantId)->where('destination_hash', $hash);
            $recent = UserPasswordReset::query()->where('tenant_id', $tenantId)->where('created_at', '>', now()->subHour());
            if ((clone $recipient)->where('created_at', '>', now()->subSeconds($timing['resend']))->exists()
                || (clone $recipient)->where('created_at', '>', now()->subHour())->count() >= (int) config('user-auth.send_limit_per_hour')
                || (clone $recent)->where('session_hash', $sessionHash)->count() >= (int) config('user-auth.send_limit_per_hour')
                || (clone $recent)->where('ip_hash', $ipHash)->count() >= 20
                || (clone $recipient)->where('delivery_uncertain', true)->whereNull('consumed_at')->whereNull('cancelled_at')->where('expires_at', '>', now())->exists()) {
                throw new DomainException('PASSWORD_RESET_COOLDOWN', 'Please wait before requesting another verification code.', 429);
            }
            if ($channel === RegistrationChannel::Email) {
                $this->emailLimit->assertAvailable($tenant, $destination);
            }
            $column = $channel === RegistrationChannel::Email ? 'email' : 'phone';
            // The only credential lookup starts with the trusted company scope.
            $user = User::query()->where('tenant_id', $tenantId)->where($column, $destination)
                ->whereNotNull($column.'_verified_at')->whereIn('status', [UserStatus::Active, UserStatus::Suspended])->lockForUpdate()->first();
            UserPasswordReset::query()->where('tenant_id', $tenantId)->where('session_hash', $sessionHash)->where('destination_hash', $hash)
                ->whereNull('consumed_at')->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
            $id = (string) Str::uuid();
            $reset = UserPasswordReset::query()->create([
                'id' => $id, 'tenant_id' => $tenantId, 'user_id' => $user?->id, 'request_id' => $requestId,
                'channel' => $channel->value, 'destination' => $destination, 'destination_hash' => $hash,
                'session_hash' => $sessionHash, 'ip_hash' => $ipHash,
                'credential_hash' => $user ? $this->credentialDigest($user) : null,
                'code_hash' => $this->hasher->hash($id, $code), 'expires_at' => now()->addSeconds($timing['ttl']), 'delivery_uncertain' => true,
            ]);
            $this->audit->record($tenantId, 'ANONYMOUS', null, 'USER_PASSWORD_RESET_REQUESTED', 'user_password_reset', $id, null, ['channel' => $channel->value]);

            return [$reset, true, $tenant];
        });
        if ($send) {
            try {
                // Same generic OTP transport for valid contacts, including unmatched contacts.
                // Receipt, delivery errors and SMTP latency must not reveal account existence.
                ($channel === RegistrationChannel::Email ? $this->emailSender : $this->smsSender)->sendVerificationCode($tenant, $destination, $code);
                $reset->update(['delivery_uncertain' => false]);
            } catch (EmailDeliveryUnknown|SmsDeliveryUnknown) {
                // Preserve the original proof after an uncertain send; never retry transport blindly.
            } catch (DomainException) {
                $reset->update(['delivery_uncertain' => false, 'cancelled_at' => now()]);
                throw new DomainException('PASSWORD_RESET_DELIVERY_UNAVAILABLE', 'Verification delivery is unavailable. Please contact support.', 503);
            }
        }

        return $reset;
    }

    public function complete(string $tenantId, string $resetId, #[\SensitiveParameter] string $binding,
        #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $password): void
    {
        $error = DB::transaction(function () use ($tenantId, $resetId, $binding, $code, $password): bool {
            $this->lockTenant($tenantId);
            $snapshot = UserPasswordReset::query()->where('tenant_id', $tenantId)->whereKey($resetId)->first();
            if (! $snapshot || ! hash_equals($snapshot->session_hash, $this->digest($binding))) {
                return true;
            }
            $user = $snapshot->user_id ? User::query()->where('tenant_id', $tenantId)->whereKey($snapshot->user_id)->lockForUpdate()->first() : null;
            $reset = UserPasswordReset::query()->where('tenant_id', $tenantId)->whereKey($resetId)->lockForUpdate()->firstOrFail();
            if ($reset->consumed_at) {
                return ! $this->hasher->verify($reset->id, $code, $reset->code_hash); // Acknowledge only, never reapply.
            }
            if ($reset->cancelled_at || $reset->expires_at->isPast() || $reset->attempt_count >= (int) config('user-auth.otp_max_attempts')) {
                return true;
            }
            if (! $this->hasher->verify($reset->id, $code, $reset->code_hash)) {
                $reset->increment('attempt_count');

                return true; // Committed before raising a generic validation failure.
            }
            $column = $reset->channel === 'EMAIL' ? 'email' : 'phone';
            if (! $user || ! in_array($user->status, [UserStatus::Active, UserStatus::Suspended], true)
                || ! $user->{$column.'_verified_at'} || $user->{$column} !== $reset->destination
                || ! hash_equals($reset->credential_hash, $this->credentialDigest($user))) {
                $reset->update(['cancelled_at' => now()]);

                return true;
            }
            $user->forceFill(['password_hash' => Hash::make($password), 'session_version' => $user->session_version + 1])->save();
            $reset->update(['consumed_at' => now()]);
            $this->audit->record($tenantId, 'USER', $user->id, 'USER_PASSWORD_RESET', 'user_password_reset', $reset->id);

            return false;
        });
        if ($error) {
            throw new DomainException('PASSWORD_RESET_INVALID', self::INVALID);
        }
    }

    public function view(string $tenantId, string $resetId, #[\SensitiveParameter] string $binding): array
    {
        $reset = UserPasswordReset::query()->where('tenant_id', $tenantId)->whereKey($resetId)
            ->where('session_hash', $this->digest($binding))->first();
        if (! $reset) {
            throw new DomainException('PASSWORD_RESET_INVALID', self::INVALID);
        }

        return ['id' => $reset->id, 'channel' => $reset->channel, 'expiresAt' => $reset->expires_at->toIso8601String()];
    }

    private function lockTenant(string $tenantId): Tenant
    {
        $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
        if (! in_array($tenant->status, [TenantStatus::Active, TenantStatus::Suspended], true)) {
            throw new DomainException('ACCOUNT_RESTRICTED', 'Account changes are unavailable.', 403);
        }

        return $tenant;
    }

    private function credentialDigest(User $user): string
    {
        return $this->digest(json_encode([$user->tenant_id, $user->id, $user->password_hash, $user->session_version, $user->email, $user->phone], JSON_THROW_ON_ERROR));
    }

    private function digest(#[\SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('user-auth.otp_secret'));
    }
}
