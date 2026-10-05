<?php

namespace App\Application\Kyc;

use App\Application\Media\DirectKycImage;
use App\Application\Media\DirectImageUploads;
use App\Application\Media\ImageStorage;
use App\Application\Media\VerifiedDirectImage;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycOcrStatus;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class SubmitKycApplicationAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(Tenant $tenant, User $user, string $country, string $identityNumber, UploadedFile|VerifiedDirectImage|DirectKycImage $front, UploadedFile|VerifiedDirectImage|DirectKycImage|null $back, ?string $requestId = null, KycDocumentType $documentType = KycDocumentType::NationalId, bool $reverify = false): KycApplication
    {
        $country = strtoupper(trim($country));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new DomainException('DOCUMENT_COUNTRY_INVALID', 'Choose a valid ISO country code.');
        }
        if ($documentType === KycDocumentType::NationalId && ($country !== 'CN' || ! $back)) {
            throw new DomainException('KYC_DOCUMENT_INVALID', 'Upload both sides of a mainland China identity card.');
        }
        $settings = PlatformKycSetting::current();
        if (! $settings->enabled || $tenant->status !== TenantStatus::Active || $user->status !== UserStatus::Active) {
            throw new DomainException('KYC_SUBMISSION_UNAVAILABLE', 'Identity verification submission is not currently available.', 403);
        }
        if ($user->tenant_id !== $tenant->id) {
            abort(404);
        }
        if ($front instanceof DirectKycImage || $back instanceof DirectKycImage) {
            throw new DomainException('IMAGE_STORAGE_CHANGED', 'Please upload the document images again to retain server originals.', 409);
        }
        // Multipart clients use the same durable local staging as direct clients.
        // Keep cleanup records outside the application transaction if either side
        // cannot be accepted; filesystem writes cannot be rolled back with SQL.
        $stage = function ($file, string $side) use ($tenant, $user) {
            if (! $file instanceof UploadedFile) return $file;
            $uploads = app(DirectImageUploads::class);
            $ticket = $uploads->authorize($tenant->id, $user->id, 'kyc', $side, $file->getMimeType());
            $uploads->backup($tenant->id, $user->id, $ticket['id'], $file->getContent());
            $uploads->complete($tenant->id, $user->id, $ticket['id']);
            return $uploads->resolve($tenant->id, $user->id, $ticket['id'], 'kyc', $side);
        };
        $front = $stage($front, 'front');
        $back = $stage($back, 'back');
        // Scope and content are validated before this action. A direct upload ID is
        // the stable submission key, including a replay after its single-use claim.
        $submissionKey = $front instanceof VerifiedDirectImage || $front instanceof DirectKycImage ? $front->id : $requestId;
        $part = static fn ($file) => $file === null ? null : ($file instanceof VerifiedDirectImage || $file instanceof DirectKycImage ? $file->id : hash('sha256', $file->getContent()));
        $fingerprint = hash('sha256', json_encode([$documentType->value, $country, $reverify, $part($front), $part($back)], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenant, $user, $country, $front, $back, $requestId, $documentType, $reverify, $submissionKey, $fingerprint): KycApplication {
            $currentTenant = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $currentUser = User::where('tenant_id', $tenant->id)->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($submissionKey) {
                $existing = KycApplication::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('submission_key', $submissionKey)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->submission_fingerprint, $fingerprint), 409);
                    return $existing;
                }
            }
            $settings = PlatformKycSetting::current(true);
            if (! $settings->enabled || $currentTenant->status !== TenantStatus::Active || $currentUser->status !== UserStatus::Active) {
                throw new DomainException('KYC_SUBMISSION_UNAVAILABLE', 'Identity verification submission is not currently available.', 403);
            }
            $identity = IdentityRecord::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
            abort_unless($reverify === (bool) $identity, 409);
            $latest = KycApplication::where('tenant_id', $tenant->id)->where('user_id', $user->id)->latest('submitted_at')->lockForUpdate()->first();
            if ($latest && ! in_array($latest->review_status, [KycReviewStatus::ResubmissionRequired, KycReviewStatus::Rejected], true)
                && ! ($latest->review_status === KycReviewStatus::Pending && $latest->processing_status === 'FAILED')
                && ! ($reverify && $latest->review_status === KycReviewStatus::Approved)) {
                throw new DomainException('KYC_ALREADY_PENDING', 'Identity verification is already under review.', 409);
            }
            $id = (string) Str::uuid();
            $disk = (string) config('kyc.document_disk');
            $base = "kyc/{$tenant->id}/{$user->id}/{$id}";
            $images = app(ImageStorage::class);
            $frontKey = $base.'/front/'.Str::uuid();
            $backKey = $back ? $base.'/back/'.Str::uuid() : null;
            $images->putUpload($tenant->id, $disk, $frontKey, $front, 'kyc', $id);
            if ($back && $backKey) {
                $images->putUpload($tenant->id, $disk, $backKey, $back, 'kyc', $id);
            }
            $application = new KycApplication;
            $application->forceFill([
                'id' => $id, 'tenant_id' => $tenant->id, 'user_id' => $user->id, 'resubmission_of_id' => $latest?->id,
                'document_type' => $documentType, 'document_country' => $country,
                'identity_number_encrypted' => null, 'identity_hash' => null,
                'front_object_key' => $frontKey, 'back_object_key' => $backKey,
                'ocr_status' => KycOcrStatus::NotStarted, 'review_status' => KycReviewStatus::Pending,
                'submitted_at' => now(), 'processing_status' => 'QUEUED', 'processing_generation' => 1,
                'submission_request_id' => $requestId, 'next_processing_at' => now(), 'submission_key' => $submissionKey, 'submission_fingerprint' => $fingerprint,
            ])->save();
            $this->audit->record($tenant->id, 'USER', $user->id, 'KYC_APPLICATION_SUBMITTED', 'kyc_application', $id, null,
                ['document_type' => $documentType->value, 'document_country' => $country, 'processing_status' => 'QUEUED'], $requestId);
            // The durable scheduler picks this up after commit, even if queue dispatch
            // is temporarily unavailable. No OCR or remote storage in this request.
            return $application;
        });
    }
}
