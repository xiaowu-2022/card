<?php

namespace App\Application\Kyc;

use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Domain\Kyc\Services\KycStatusService;

final readonly class UserKycQuery
{
    public function __construct(private KycStatusService $statuses, private IdentityNumberProtector $identities) {}

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $userId): array
    {
        $status = $this->statuses->forUser($tenantId, $userId);
        $application = KycApplication::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->latest('submitted_at')->first();
        $identity = $status === KycUserStatus::Approved
            ? IdentityRecord::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->first()
            : null;

        return [
            'status' => $status->value,
            'reviewMessage' => $application?->review_message,
            'submittedAt' => $application?->submitted_at?->toIso8601String(),
            'verifiedAt' => $identity?->verified_at?->toIso8601String(),
            'documentCountry' => $identity?->document_country ?? $application?->document_country,
            'maskedIdentityNumber' => $identity ? $this->identities->maskEncrypted($identity->identity_number_encrypted) : null,
        ];
    }
}
