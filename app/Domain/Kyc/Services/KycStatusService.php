<?php

namespace App\Domain\Kyc\Services;

use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;

final class KycStatusService
{
    public function forUser(string $tenantId, string $userId): KycUserStatus
    {
        if (IdentityRecord::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->exists()) {
            return KycUserStatus::Approved;
        }

        $latest = KycApplication::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->latest('submitted_at')->first();

        return match ($latest?->review_status) {
            KycReviewStatus::Pending => KycUserStatus::Pending,
            KycReviewStatus::Rejected => KycUserStatus::Rejected,
            KycReviewStatus::ResubmissionRequired => KycUserStatus::ResubmissionRequired,
            KycReviewStatus::Approved => KycUserStatus::Approved,
            default => KycUserStatus::NotSubmitted,
        };
    }
}
