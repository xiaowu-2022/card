<?php

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Models\PlatformEmailProfile;
use Illuminate\Support\Facades\DB;

final class TenantEmailPolicy
{
    public function settings(string $tenantId): ?PlatformEmailProfile
    {
        $profileId = DB::table('tenant_notification_profiles')->where('tenant_id', $tenantId)->value('email_profile_id');

        return $profileId ? PlatformEmailProfile::query()->whereKey($profileId)->first() : null;
    }

    public function dailyLimit(string $tenantId): int
    {
        return $this->settings($tenantId)?->daily_recipient_limit ?? 10;
    }
}
