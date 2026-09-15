<?php

namespace App\Application\User;

use App\Domain\Notification\Services\TenantSmsPolicy;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserContactChange;
use App\Domain\User\Services\ContactMasker;

final readonly class AccountInformationQuery
{
    public function __construct(private ContactMasker $masker, private ChangeUserContactAction $changes) {}

    public function execute(string $tenantId, string $userId, ?string $requestId, string $sessionBinding): array
    {
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        $change = $requestId ? UserContactChange::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('request_id', $requestId)->where('session_hash', $this->changes->digest($sessionBinding))
            ->whereNull('consumed_at')->whereNull('cancelled_at')->where('expires_at', '>', now())
            ->where('attempt_count', '<', (int) config('user-auth.otp_max_attempts'))->first() : null;

        return [
            'canEdit' => $tenant->status === TenantStatus::Active && $user->status === UserStatus::Active,
            'email' => $user->email ? $this->masker->mask(RegistrationChannel::Email, $user->email) : null,
            'phone' => $user->phone ? $this->masker->mask(RegistrationChannel::Phone, $user->phone) : null,
            'challenge' => $change ? [
                'id' => $change->id, 'channel' => $change->channel,
                'destination' => $this->masker->mask(RegistrationChannel::from($change->channel), $change->destination),
                'expiresAt' => $change->expires_at->toIso8601String(),
                'resendAt' => ($change->delivery_uncertain ? $change->expires_at : $change->created_at->copy()->addSeconds($change->channel === 'PHONE' ? app(TenantSmsPolicy::class)->timing($tenantId)['resend'] : (int) config('user-auth.resend_cooldown_seconds')))->toIso8601String(), 'deliveryUncertain' => $change->delivery_uncertain,
            ] : null,
        ];
    }
}
