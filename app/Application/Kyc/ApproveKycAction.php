<?php

namespace App\Application\Kyc;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Tenant\Models\TenantKycSetting;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ApproveKycAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(string $tenantId, string $applicationId, AdminUser $reviewer, ?string $requestId = null): IdentityRecord
    {
        return DB::transaction(function () use ($tenantId, $applicationId, $reviewer, $requestId): IdentityRecord {
            $application = KycApplication::query()->where('tenant_id', $tenantId)->whereKey($applicationId)->lockForUpdate()->firstOrFail();
            if ($application->review_status !== KycReviewStatus::Pending) {
                throw new DomainException('KYC_ALREADY_REVIEWED', 'This application has already been reviewed.', 409);
            }
            User::query()->where('tenant_id', $tenantId)->whereKey($application->user_id)->lockForUpdate()->firstOrFail();
            DB::select('SELECT pg_advisory_xact_lock(?)', [$this->approvalLockKey($tenantId, $application->identity_hash)]);
            $settings = TenantKycSetting::query()->where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();
            $count = IdentityRecord::query()->where('tenant_id', $tenantId)->where('identity_hash', $application->identity_hash)->count();
            if ($count >= $settings->max_accounts_per_identity) {
                throw new DomainException('IDENTITY_ACCOUNT_LIMIT_REACHED', 'This identity has reached the Tenant account limit.', 409);
            }

            $identity = new IdentityRecord;
            $identity->forceFill([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $application->user_id,
                'source_kyc_application_id' => $application->id, 'document_type' => $application->document_type,
                'document_country' => $application->document_country, 'identity_number_encrypted' => $application->identity_number_encrypted,
                'identity_hash' => $application->identity_hash, 'verified_at' => now(),
            ])->save();
            $application->forceFill([
                'review_status' => KycReviewStatus::Approved, 'reviewed_by_admin_user_id' => $reviewer->id,
                'reviewed_at' => now(), 'review_reason_code' => null, 'review_message' => null,
            ])->save();
            $this->audit->record($tenantId, 'ADMIN', $reviewer->id, 'KYC_APPLICATION_APPROVED', 'kyc_application', $application->id, ['review_status' => KycReviewStatus::Pending->value], ['review_status' => KycReviewStatus::Approved->value], $requestId);

            return $identity;
        });
    }

    private function approvalLockKey(string $tenantId, string $identityHash): int
    {
        return intval(substr(hash('sha256', $tenantId.'|'.$identityHash), 0, 15), 16);
    }
}
