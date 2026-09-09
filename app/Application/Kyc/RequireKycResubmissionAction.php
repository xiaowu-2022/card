<?php

namespace App\Application\Kyc;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Enums\KycReviewReason;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class RequireKycResubmissionAction
{
    public function __construct(private AuditLogger $audit, private IdentityNumberProtector $identities) {}

    public function execute(string $tenantId, string $applicationId, AdminUser $reviewer, KycReviewReason $reason, string $message, ?string $requestId = null): void
    {
        DB::transaction(function () use ($tenantId, $applicationId, $reviewer, $reason, $message, $requestId): void {
            $application = KycApplication::query()->where('tenant_id', $tenantId)->whereKey($applicationId)->lockForUpdate()->firstOrFail();
            if ($application->review_status !== KycReviewStatus::Pending) {
                throw new DomainException('KYC_ALREADY_REVIEWED', 'This application has already been reviewed.', 409);
            }
            if (mb_strlen(trim($message)) < 3 || mb_strlen(trim($message)) > 500 || str_contains($message, '<') || str_contains($message, '>')) {
                throw new DomainException('REVIEW_MESSAGE_INVALID', 'Enter a reviewer message between 3 and 500 characters.');
            }
            $identity = $this->identities->decrypt($application->identity_number_encrypted);
            if ($identity !== '' && mb_stripos($message, $identity) !== false) {
                throw new DomainException('REVIEW_MESSAGE_CONTAINS_SENSITIVE_DATA', 'The reviewer message contains sensitive identity data.');
            }
            $application->forceFill(['review_status' => KycReviewStatus::ResubmissionRequired, 'review_reason_code' => $reason->value, 'review_message' => trim($message), 'reviewed_by_admin_user_id' => $reviewer->id, 'reviewed_at' => now()])->save();
            $this->audit->record($tenantId, 'ADMIN', $reviewer->id, 'KYC_RESUBMISSION_REQUIRED', 'kyc_application', $application->id, ['review_status' => KycReviewStatus::Pending->value], ['review_status' => KycReviewStatus::ResubmissionRequired->value, 'reason_code' => $reason->value], $requestId);
        });
    }
}
