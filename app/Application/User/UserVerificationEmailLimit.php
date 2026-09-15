<?php

namespace App\Application\User;

use App\Domain\Notification\Services\TenantEmailPolicy;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\RegistrationChallenge;
use App\Domain\User\Models\UserContactChange;
use App\Domain\User\Models\UserPasswordReset;
use App\Support\Errors\DomainException;

final readonly class UserVerificationEmailLimit
{
    public function __construct(private TenantEmailPolicy $policy) {}

    // All callers hold the Tenant row until their new delivery intent commits.
    public function assertAvailable(Tenant $tenant, #[\SensitiveParameter] string $email): void
    {
        $limit = $this->policy->dailyLimit($tenant->id);
        if ($limit === 0) {
            return;
        }
        $start = now()->setTimezone($tenant->timezone)->startOfDay();
        $end = $start->copy()->addDay()->utc();
        $start = $start->utc();
        $hash = hash_hmac('sha256', $tenant->id.'|EMAIL|'.$email, (string) config('user-auth.otp_secret'));
        $sent = RegistrationChallenge::query()->where('tenant_id', $tenant->id)->where('channel', 'EMAIL')
            ->where('destination', $email)->where('created_at', '>=', $start)->where('created_at', '<', $end)->count();
        foreach ([UserContactChange::class, UserPasswordReset::class] as $model) {
            $sent += $model::query()->where('tenant_id', $tenant->id)->where('channel', 'EMAIL')
                ->where('destination_hash', $hash)->where('created_at', '>=', $start)->where('created_at', '<', $end)->count();
        }
        if ($sent >= $limit) {
            throw new DomainException('EMAIL_DAILY_LIMIT', 'The daily email verification limit has been reached. Please try tomorrow.', 429);
        }
    }
}
