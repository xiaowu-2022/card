<?php

namespace App\Application\Notification;

use App\Application\Notification\DTOs\UpdateTenantSmsSettings;
use App\Application\Tenant\CompanyConfigurationAuthority;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Notification\Models\PlatformSmsProfile;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class SavePlatformSmsProfileAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(?string $profileId, string $name, #[\SensitiveParameter] UpdateTenantSmsSettings $data, AdminUser $actor, ?string $requestId = null): void
    {
        app(CompanyConfigurationAuthority::class)->assert($actor);
        validator(['name' => $name], ['name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1f\x7f]/']])->validate();
        DB::transaction(function () use ($profileId, $name, $data, $actor, $requestId): void {
            $admin = AdminUser::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $admin->memberships()->where('scope_type', 'PLATFORM')->lockForUpdate()->get();
            app(CompanyConfigurationAuthority::class)->assert($admin);
            $settings = $profileId ? PlatformSmsProfile::query()->whereKey($profileId)->lockForUpdate()->firstOrFail() : new PlatformSmsProfile([]);
            $settings->name = trim($name);
            $replacing = filled($data->accessKeyId) && filled($data->accessKeySecret);
            if (filled($data->accessKeyId) !== filled($data->accessKeySecret)) {
                throw new DomainException('SMS_CREDENTIAL_PAIR_REQUIRED', 'Replace both AccessKey fields together.');
            }
            if ($data->enabled && ! $replacing && ! $settings->credentialsConfigured()) {
                throw new DomainException('SMS_CREDENTIALS_REQUIRED', 'Configure both AccessKey fields before enabling SMS.');
            }
            if ($data->enabled && (blank($data->signName) || blank($data->verificationTemplateCode))) {
                throw new DomainException('SMS_TEMPLATE_REQUIRED', 'Configure the SMS signature and verification template before enabling SMS.');
            }
            if ($replacing) {
                $settings->access_key_id = $data->accessKeyId;
                $settings->access_key_secret = $data->accessKeySecret;
            }
            $settings->fill([
                'enabled' => $data->enabled,
                'sign_name' => $data->signName,
                'verification_template_code' => $data->verificationTemplateCode,
                'existing_account_template_code' => $data->existingAccountTemplateCode,
                'resend_interval_seconds' => $data->resendIntervalSeconds,
                'code_ttl_seconds' => $data->codeTtlSeconds,
            ])->save();
            $this->audit->record(null, 'ADMIN', $actor->id, 'PLATFORM_SMS_PROFILE_SAVED', 'platform_sms_profiles', $settings->id,
                after: ['enabled' => $settings->enabled, 'credentials_replaced' => $replacing,
                    'resend_interval_seconds' => $settings->resend_interval_seconds, 'code_ttl_seconds' => $settings->code_ttl_seconds], requestId: $requestId);
        });
    }
}
