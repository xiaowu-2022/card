<?php

namespace App\Application\Kyc;

use App\Application\Media\DirectImageUploads;
use App\Application\Media\ImageStorage;
use App\Application\Media\VerifiedDirectImage;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\Media\DirectImageUpload;
use App\Domain\Media\StoredImage;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class PreviewKycNumber
{
    public function execute(Tenant $tenant, User $user, string $uploadId, KycDocumentType $type, string $country, bool $reverify): array
    {
        abort_unless($user->tenant_id === $tenant->id, 404);
        if ($tenant->status !== TenantStatus::Active || $user->status !== UserStatus::Active || ! PlatformKycSetting::current()->enabled) {
            throw new DomainException('KYC_SUBMISSION_UNAVAILABLE', 'Identity verification submission is not currently available.', 403);
        }
        $hasIdentity = IdentityRecord::where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists();
        abort_if($reverify !== $hasIdentity, 409);
        $latest = KycApplication::where('tenant_id', $tenant->id)->where('user_id', $user->id)->latest('submitted_at')->first();
        abort_if($latest && $latest->review_status !== KycReviewStatus::ResubmissionRequired
            && ! ($reverify && in_array($latest->review_status, [KycReviewStatus::Approved, KycReviewStatus::Rejected], true)), 409);
        $lock = Cache::lock('kyc-preview:'.$uploadId, 180);
        abort_unless($lock->get(), 409);
        try {
            $file = app(DirectImageUploads::class)->resolve($tenant->id, $user->id, $uploadId, 'kyc', 'front');
            $upload = $this->scopedUpload($file);
            $ocr = $this->resultFor($file, $type, $country);
            if (! $ocr) {
                $image = StoredImage::whereKey($upload->image_id)->where('tenant_id', $tenant->id)->firstOrFail();
                $url = app(ImageStorage::class)->ocrUrl($image->source_disk, $image->source_key);
                $ocr = app(RecognizeKycNumber::class)->execute($type, $country, $url);
                $encrypted = app(KycDataCipher::class)->encrypt(json_encode([
                    'number' => $ocr->candidateIdentityNumber, 'type' => $type->value, 'country' => $country,
                    'provider' => app(KycOcrProviderInterface::class)->name(), 'sha256' => hash('sha256', $file->getContent()),
                    'tenant' => $tenant->id, 'user' => $user->id, 'upload' => $uploadId,
                ], JSON_THROW_ON_ERROR));
                DB::transaction(function () use ($file, $encrypted) {
                    $current = $this->scopedUpload($file, true);
                    $current->update(['kyc_ocr_evidence_encrypted' => $encrypted]);
                });
            }

            return ['identityNumber' => $ocr->candidateIdentityNumber, 'frontUploadId' => $uploadId, 'expiresAt' => $upload->expires_at->toIso8601String()];
        } finally {
            $lock->release();
        }
    }

    public function resultFor(VerifiedDirectImage $file, KycDocumentType $type, string $country): ?KycOcrResultDTO
    {
        $upload = $this->scopedUpload($file);
        if (! $upload->kyc_ocr_evidence_encrypted) {
            return null;
        }
        try {
            $evidence = json_decode(app(KycDataCipher::class)->decrypt($upload->kyc_ocr_evidence_encrypted), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($evidence) || ($evidence['tenant'] ?? null) !== $file->tenantId || ($evidence['user'] ?? null) !== $file->userId
                || ($evidence['upload'] ?? null) !== $file->id || ($evidence['type'] ?? null) !== $type->value || ($evidence['country'] ?? null) !== $country
                || ($evidence['provider'] ?? null) !== app(KycOcrProviderInterface::class)->name()
                || ($evidence['sha256'] ?? null) !== hash('sha256', $file->getContent()) || ! is_string($evidence['number'] ?? null) || $evidence['number'] === '') {
                throw new \RuntimeException;
            }

            return new KycOcrResultDTO(KycOcrOutcome::Success, $evidence['number']);
        } catch (\Throwable) {
            throw new DomainException('KYC_OCR_PREVIEW_EXPIRED', 'Please select and recognize the front image again.', 409);
        }
    }

    private function scopedUpload(VerifiedDirectImage $file, bool $lock = false): DirectImageUpload
    {
        abort_unless($file->purpose === 'kyc' && $file->field === 'front', 422);
        $query = DirectImageUpload::whereKey($file->id)->where('tenant_id', $file->tenantId)->where('user_id', $file->userId);
        $upload = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        abort_unless($upload->purpose === 'kyc' && $upload->field === 'front' && $upload->verified_at, 422);
        abort_if($upload->claimed_at, 409);
        abort_if($upload->expires_at->isPast(), 410);

        return $upload;
    }
}
