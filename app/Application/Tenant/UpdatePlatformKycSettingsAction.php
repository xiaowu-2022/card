<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\KycReviewMode;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class UpdatePlatformKycSettingsAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(bool $enabled, int $limit, KycReviewMode $mode, bool $automaticConfirmed, AdminUser $actor, ?string $requestId = null): void
    {
        if ($limit < 1 || $limit > 100 || ! in_array($mode, [KycReviewMode::Manual, KycReviewMode::Automatic], true)
            || ($mode === KycReviewMode::Automatic && ! $automaticConfirmed)) {
            throw new DomainException('KYC_POLICY_INVALID', 'Confirm a valid identity verification policy.');
        }
        DB::transaction(function () use ($enabled, $limit, $mode, $actor, $requestId): void {
            $admin = AdminUser::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $admin->memberships()->where('scope_type', ScopeType::Platform)->whereNull('scope_id')->lockForUpdate()->get();
            app(CompanyConfigurationAuthority::class)->assert($admin);
            $settings = PlatformKycSetting::current(true);
            $before = $settings->policy();
            $settings->update(['enabled' => $enabled, 'max_accounts_per_identity' => $limit, 'review_mode' => $mode]);
            $this->audit->record(null, 'ADMIN', $admin->id, 'PLATFORM_KYC_SETTINGS_UPDATED', 'platform_kyc_settings', $settings->id, $before, $settings->policy(), $requestId);
        });
    }
}
