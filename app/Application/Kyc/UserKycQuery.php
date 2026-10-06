<?php

namespace App\Application\Kyc;

use App\Application\Media\ImagePresentation;
use App\Application\Media\ImageStorage;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Domain\Kyc\Services\KycStatusService;
use App\Infrastructure\Storage\OssImages;

final readonly class UserKycQuery
{
    public function __construct(private KycStatusService $statuses, private IdentityNumberProtector $identities) {}

    /** @return array<string, mixed> */
    public function get(string $tenantId, string $userId): array
    {
        $platformVerifiedAt = \Illuminate\Support\Facades\DB::table('platform_user_creations')->where('tenant_id', $tenantId)->where('user_id', $userId)->value('verified_at');
        $status = $this->statuses->forUser($tenantId, $userId);
        $application = KycApplication::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->latest('submitted_at')->first();
        $identity = $status === KycUserStatus::Approved
            ? IdentityRecord::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->first()
            : null;

        $processingStatus = $application?->review_status?->value === "PENDING" ? $application->processing_status : null;
        // Expose only public failure categories from the latest attempt, before
        // switching to approved history for the existing identity photos.
        $processingError = $processingStatus === 'FAILED'
            ? (in_array($application->processing_error, [
                'IDENTITY_ACCOUNT_LIMIT_REACHED', 'KYC_OCR_MISMATCH', 'KYC_OCR_UNAVAILABLE',
                'KYC_DOCUMENT_STORAGE_FAILED', 'KYC_DOCUMENT_INTEGRITY_FAILED', 'KYC_SUBMISSION_UNAVAILABLE',
            ], true) ? $application->processing_error : 'KYC_PROCESSING_UNAVAILABLE')
            : null;
        $reverificationPending = $identity && $application?->review_status?->value === 'PENDING' && $processingStatus !== 'FAILED';
        if ($identity) {
            $application = KycApplication::where('tenant_id', $tenantId)->where('user_id', $userId)
                ->whereKey($identity->source_kyc_application_id)->firstOrFail();
        }
        $images = app(ImageStorage::class);
        $disk = (string) config('kyc.document_disk');

        return [
            'status' => $status->value,
            'processingStatus' => $processingStatus,
            'processingError' => $processingError,
            'reverificationPending' => (bool) $reverificationPending,
            'documentType' => $identity?->document_type?->value ?? $application?->document_type?->value,
            'frontUrl' => $identity && $application?->front_object_key ? $this->photoUrl($images, $tenantId, $disk, $application->front_object_key) : null,
            'backUrl' => $identity && $application?->back_object_key ? $this->photoUrl($images, $tenantId, $disk, $application->back_object_key) : null,
            'frontSources' => $identity && $application?->front_object_key ? $images->previewSources($disk, $application->front_object_key, 'thumbnail') : [],
            'backSources' => $identity && $application?->back_object_key ? $images->previewSources($disk, $application->back_object_key, 'thumbnail') : [],
            'frontOriginalSources' => $identity && $application?->front_object_key ? $images->previewSources($disk, $application->front_object_key, 'original') : [],
            'backOriginalSources' => $identity && $application?->back_object_key ? $images->previewSources($disk, $application->back_object_key, 'original') : [],
            'reviewMessage' => $application?->review_message,
            'submittedAt' => $application?->submitted_at?->toIso8601String(),
            'verifiedAt' => $identity ? ($application?->reviewed_at ?? $identity->verified_at)?->toIso8601String() : ($platformVerifiedAt ? \Illuminate\Support\Carbon::parse($platformVerifiedAt)->toIso8601String() : null),
            'documentCountry' => $identity?->document_country ?? $application?->document_country,
            'maskedIdentityNumber' => $identity ? $this->identities->maskEncrypted($identity->identity_number_encrypted) : null,
        ];
    }

    private function photoUrl(ImageStorage $images, string $tenant, string $disk, string $key): ?string
    {
        $config = $images->active();
        if (! $config) {
            return $images->displayUrl($disk, $key, 'thumbnail');
        }
        $image = $images->record($disk, $key);
        if (! $image || $image->tenant_id !== $tenant || $image->state !== 'ready') {
            return null;
        }
        $url = app(OssImages::class)->url($config, $image->object_key);
        $process = ImagePresentation::process('thumbnail', $image->mime);

        return $url.($process ? '?x-oss-process='.rawurlencode($process) : '');
    }
}
