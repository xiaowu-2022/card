<?php

namespace App\Application\Notification;

use App\Domain\Notification\Models\PlatformSmsProfile;
use App\Domain\Notification\Services\TenantSmsPolicy;

final readonly class TenantSmsSettingsQuery
{
    public function __construct(private TenantSmsPolicy $policy) {}

    public function execute(string $tenantId, bool $includeProfiles = false): array
    {
        $settings = $this->policy->settings($tenantId);

        return [
            'profileId' => $settings?->id,
            'profileName' => $settings?->name,
            'available' => $settings?->available() ?? false,
            ...($includeProfiles ? ['profiles' => PlatformSmsProfile::query()->orderBy('name')->get()->map(fn ($profile): array => ['id' => $profile->id, 'name' => $profile->name, 'available' => $profile->available()])->all()] : []),
            'enabled' => $settings?->enabled ?? false,
            'credentialsConfigured' => $settings?->credentialsConfigured() ?? false,
            'signName' => $settings?->sign_name ?? '',
            'verificationTemplateCode' => $settings?->verification_template_code ?? '',
            'existingAccountTemplateCode' => $settings?->existing_account_template_code ?? '',
            'resendIntervalSeconds' => $settings?->resend_interval_seconds ?? 60,
            'codeTtlSeconds' => $settings?->code_ttl_seconds ?? 600,
        ];
    }
}
