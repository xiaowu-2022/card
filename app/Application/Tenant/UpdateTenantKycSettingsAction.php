<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\KycReviewMode;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTenantKycSettingsAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(Tenant $tenant, bool $enabled, ?int $maxAccountsPerIdentity, AdminUser $actor, ?string $requestId = null): void
    {
        DB::transaction(function () use ($tenant, $enabled, $maxAccountsPerIdentity, $actor, $requestId): void {
            $settings = $tenant->kycSettings()->lockForUpdate()->firstOrFail();
            $fields = ['enabled', 'max_accounts_per_identity', 'review_mode'];
            $before = $settings->only($fields);
            $settings->update([
                'enabled' => $enabled,
                'max_accounts_per_identity' => $maxAccountsPerIdentity,
                'review_mode' => KycReviewMode::Manual,
            ]);
            $this->audit->record($tenant->id, 'ADMIN', $actor->id, 'TENANT_KYC_SETTINGS_UPDATED', 'tenant_kyc_settings', $tenant->id, $before, $settings->fresh()->only($fields), $requestId);
        });
    }
}
