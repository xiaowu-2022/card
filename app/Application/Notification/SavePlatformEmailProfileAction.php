<?php

namespace App\Application\Notification;

use App\Application\Notification\DTOs\UpdateTenantEmailSettings;
use App\Application\Tenant\CompanyConfigurationAuthority;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Notification\Models\PlatformEmailProfile;
use App\Support\Errors\DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class SavePlatformEmailProfileAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(?string $profileId, string $name, #[\SensitiveParameter] UpdateTenantEmailSettings $data, AdminUser $actor, ?string $requestId = null): void
    {
        app(CompanyConfigurationAuthority::class)->assert($actor);
        validator(['name' => $name], ['name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1f\x7f]/']])->validate();
        DB::transaction(function () use ($profileId, $name, $data, $actor, $requestId): void {
            $admin = AdminUser::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $admin->memberships()->where('scope_type', 'PLATFORM')->lockForUpdate()->get();
            app(CompanyConfigurationAuthority::class)->assert($admin);
            $settings = $profileId ? PlatformEmailProfile::query()->whereKey($profileId)->lockForUpdate()->firstOrFail() : new PlatformEmailProfile(['configuration_version' => (string) Str::uuid()]);
            $settings->name = trim($name);
            if (blank($data->smtpToken) && $settings->tokenConfigured() && $settings->from_address !== $data->fromAddress) {
                throw new DomainException('SMTP_TOKEN_REPLACEMENT_REQUIRED', 'Changing the SMTP account requires a new token for that address.');
            }
            if ($data->enabled && (blank($data->fromAddress) || blank($data->fromName) || (! $settings->tokenConfigured() && blank($data->smtpToken)))) {
                throw new DomainException('EMAIL_SETTINGS_INCOMPLETE', 'Configure the sender and SMTP token before enabling email.');
            }
            if (filled($data->smtpToken)) {
                $changed = ! $settings->tokenConfigured();
                if (! $changed) {
                    try {
                        $changed = ! hash_equals($settings->smtp_token, $data->smtpToken);
                    } catch (DecryptException) {
                        $changed = true;
                    }
                }
                $settings->smtp_token = $data->smtpToken;
                if ($changed) {
                    $settings->configuration_version = (string) Str::uuid();
                }
            }
            $settings->fill(['enabled' => $data->enabled, 'from_address' => $data->fromAddress, 'from_name' => $data->fromName, 'daily_recipient_limit' => $data->dailyRecipientLimit])->save();
            $this->audit->record(null, 'ADMIN', $actor->id, 'PLATFORM_EMAIL_PROFILE_SAVED', 'platform_email_profiles', $settings->id,
                after: ['enabled' => $settings->enabled, 'token_replaced' => filled($data->smtpToken), 'daily_recipient_limit' => $data->dailyRecipientLimit], requestId: $requestId);
        });
    }
}
