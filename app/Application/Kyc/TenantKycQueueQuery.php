<?php

namespace App\Application\Kyc;

use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Domain\Kyc\Services\KycDataCipher;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final readonly class TenantKycQueueQuery
{
    public function __construct(private IdentityNumberProtector $identities, private KycDataCipher $cipher) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function paginate(string $tenantId, ?string $search, ?string $status, ?string $date): LengthAwarePaginator
    {
        $query = KycApplication::query()->where('tenant_id', $tenantId)->with(['user.profile']);
        if ($search) {
            $query->whereHas('user', fn ($user) => $user->where('tenant_id', $tenantId)->where(function ($q) use ($search): void {
                if (Str::isUuid($search)) {
                    $q->orWhere('id', $search);
                }
                $q->orWhere('email', 'ilike', '%'.addcslashes($search, '%_').'%')->orWhere('phone', 'like', '%'.addcslashes($search, '%_').'%');
            }));
        }
        if ($status) {
            $query->where('review_status', $status);
        }
        if ($date) {
            $query->whereDate('submitted_at', $date);
        }

        return $query->latest('submitted_at')->paginate(20)->through(fn (KycApplication $application) => [
            'id' => $application->id,
            'user' => ['id' => $application->user_id, 'displayName' => $application->user->profile?->display_name, 'contact' => $application->user->email ?? $application->user->phone],
            'documentCountry' => $application->document_country,
            'ocrStatus' => $application->ocr_status->value,
            'reviewStatus' => $application->review_status->value,
            'submittedAt' => $application->submitted_at->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    public function detail(string $tenantId, string $applicationId): array
    {
        $application = KycApplication::query()->where('tenant_id', $tenantId)->whereKey($applicationId)->with(['user.profile', 'reviewer'])->firstOrFail();
        $ocr = $application->ocr_result_encrypted ? json_decode($this->cipher->decrypt($application->ocr_result_encrypted), true, 8, JSON_THROW_ON_ERROR) : null;

        return [
            'application' => [
                'id' => $application->id,
                'user' => ['id' => $application->user_id, 'displayName' => $application->user->profile?->display_name, 'contact' => $application->user->email ?? $application->user->phone],
                'documentType' => $application->document_type->value,
                'documentCountry' => $application->document_country,
                'maskedIdentityNumber' => $this->identities->maskEncrypted($application->identity_number_encrypted),
                'documentsSubmitted' => true,
                'ocrStatus' => $application->ocr_status->value,
                'ocrSummary' => $ocr ? ['identityMatch' => $ocr['candidate_identity_match'] ?? 'UNKNOWN', 'candidateName' => $ocr['candidate_name'], 'confidence' => $ocr['confidence']] : null,
                'reviewStatus' => $application->review_status->value,
                'reviewReasonCode' => $application->review_reason_code,
                'reviewMessage' => $application->review_message,
                'reviewerName' => $application->reviewer?->name,
                'automaticallyApproved' => $application->automatically_approved,
                'submittedAt' => $application->submitted_at->toIso8601String(),
                'reviewedAt' => $application->reviewed_at?->toIso8601String(),
            ],
        ];
    }
}
