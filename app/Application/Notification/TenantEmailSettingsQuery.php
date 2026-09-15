<?php

namespace App\Application\Notification;

use App\Domain\Notification\Models\PlatformEmailProfile;
use App\Domain\Notification\Services\TenantEmailPolicy;

final readonly class TenantEmailSettingsQuery
{
    public function __construct(private TenantEmailPolicy $policy) {}

    public function execute(string $tenantId, bool $includeProfiles = false): array
    {
        $settings = $this->policy->settings($tenantId);

        return [
            'profileId' => $settings?->id,
            'profileName' => $settings?->name,
            'available' => $settings?->available() ?? false,
            ...($includeProfiles ? ['profiles' => PlatformEmailProfile::query()->orderBy('name')->get()->map(fn ($profile): array => ['id' => $profile->id, 'name' => $profile->name, 'available' => $profile->available()])->all()] : []),
            'enabled' => $settings?->enabled ?? false,
            'tokenConfigured' => $settings?->tokenConfigured() ?? false,
            'fromAddress' => $settings?->from_address ?? '',
            'fromName' => $settings?->from_name ?? '',
            'dailyRecipientLimit' => $settings?->daily_recipient_limit ?? 10,
        ];
    }
}
