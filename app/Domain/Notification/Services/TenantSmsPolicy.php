<?php

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Models\PlatformSmsProfile;
use Illuminate\Support\Facades\DB;

final class TenantSmsPolicy
{
    /** No process-global credential cache: every lookup uses the trusted tenant. */
    public function settings(string $tenantId): ?PlatformSmsProfile
    {
        $profileId = DB::table('tenant_notification_profiles')->where('tenant_id', $tenantId)->value('sms_profile_id');

        return $profileId ? PlatformSmsProfile::query()->whereKey($profileId)->first() : null;
    }

    /** @return array{resend:int,ttl:int} */
    public function timing(string $tenantId): array
    {
        $settings = $this->settings($tenantId);

        return ['resend' => $settings?->resend_interval_seconds ?? 60, 'ttl' => $settings?->code_ttl_seconds ?? 600];
    }
}
