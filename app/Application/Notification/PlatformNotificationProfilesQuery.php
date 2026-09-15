<?php

namespace App\Application\Notification;

use App\Domain\Notification\Models\PlatformEmailProfile;
use App\Domain\Notification\Models\PlatformSmsProfile;
use Illuminate\Support\Facades\DB;

final class PlatformNotificationProfilesQuery
{
    public function execute(string $channel): array
    {
        abort_unless(in_array($channel, ['sms', 'email'], true), 404);
        $model = $channel === 'sms' ? PlatformSmsProfile::class : PlatformEmailProfile::class;
        $counts = DB::table('tenant_notification_profiles')->selectRaw($channel.'_profile_id AS profile_id, count(*) AS total')->groupBy($channel.'_profile_id')->pluck('total', 'profile_id');

        return $model::query()->orderBy('created_at')->get()->map(fn ($profile): array => [
            'id' => $profile->id, 'name' => $profile->name, 'enabled' => $profile->enabled,
            'available' => $profile->available(), 'companyCount' => (int) ($counts[$profile->id] ?? 0),
            ...($channel === 'sms' ? [
                'credentialsConfigured' => $profile->credentialsConfigured(), 'signName' => $profile->sign_name,
                'verificationTemplateCode' => $profile->verification_template_code, 'existingAccountTemplateCode' => $profile->existing_account_template_code ?? '',
                'resendIntervalSeconds' => $profile->resend_interval_seconds, 'codeTtlSeconds' => $profile->code_ttl_seconds,
            ] : [
                'tokenConfigured' => $profile->tokenConfigured(), 'fromAddress' => $profile->from_address,
                'fromName' => $profile->from_name, 'dailyRecipientLimit' => $profile->daily_recipient_limit,
            ]),
        ])->all();
    }
}
