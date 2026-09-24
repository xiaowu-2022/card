<?php

namespace App\Application\Kyc;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Kyc\Enums\KycOcrStatus;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\IdentityNumberNormalizer;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\Tenant\Enums\KycReviewMode;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class SubmitKycApplicationAction
{
    public function __construct(private IdentityNumberProtector $identities, private AuditLogger $audit, private ApproveKycAction $approve) {}

    public function execute(Tenant $tenant, User $user, string $country, string $identityNumber, UploadedFile $front, ?UploadedFile $back, ?string $requestId = null, KycDocumentType $documentType = KycDocumentType::NationalId): KycApplication
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
        if (IdentityRecord::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists()) {
            throw new DomainException('KYC_ALREADY_APPROVED', 'Your identity is already verified.');
        }
        $latest = KycApplication::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->latest('submitted_at')->first();
        if ($latest && $latest->review_status !== KycReviewStatus::ResubmissionRequired) {
            throw new DomainException('KYC_ALREADY_PENDING', 'Identity verification is already under review.');
        }
        $provider = app(KycOcrProviderInterface::class);
        try {
            $ocr = $provider->extractIdentityDocument(new KycOcrRequestDTO($documentType, $country, $front->getContent(), $back?->getContent() ?? ''));
        } catch (Throwable) {
            throw new DomainException('KYC_OCR_UNAVAILABLE', 'Document recognition is temporarily unavailable. Please try again later.', 503);
        }
        $normalizer = app(IdentityNumberNormalizer::class);
        if ($ocr->outcome !== KycOcrOutcome::Success || ! $ocr->candidateIdentityNumber
            || ! hash_equals($normalizer->normalize($identityNumber), $normalizer->normalize($ocr->candidateIdentityNumber))) {
            throw new DomainException('KYC_OCR_MISMATCH', 'The document number could not be recognized or does not match. Please upload a clear document image.');
        }
        $applicationId = (string) Str::uuid();
        $disk = (string) config('kyc.document_disk');
        $base = "kyc/{$tenant->id}/{$user->id}/{$applicationId}";
        $frontKey = "{$base}/front/".(string) Str::uuid();
        $backKey = $back ? "{$base}/back/".(string) Str::uuid() : null;
        $stored = [];

        try {
            if (! Storage::disk($disk)->putFileAs(dirname($frontKey), $front, basename($frontKey))) {
                throw new DomainException('KYC_DOCUMENT_STORAGE_FAILED', 'The documents could not be stored. Please try again.', 503);
            }
            $stored[] = $frontKey;
            if ($back && ! Storage::disk($disk)->putFileAs(dirname($backKey), $back, basename($backKey))) {
                throw new DomainException('KYC_DOCUMENT_STORAGE_FAILED', 'The documents could not be stored. Please try again.', 503);
            }
            if ($backKey) {
                $stored[] = $backKey;
            }
            $protected = $this->identities->protect($tenant->id, $documentType->value, $country, $identityNumber);

            $application = DB::transaction(function () use ($tenant, $user, $country, $applicationId, $frontKey, $backKey, $protected, $requestId, $documentType, $ocr, $provider): KycApplication {
                $currentTenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $currentUser = User::query()->where('tenant_id', $tenant->id)->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $latest = KycApplication::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->latest('submitted_at')->lockForUpdate()->first();
                $settings = PlatformKycSetting::current(true);
                if ($currentTenant->status !== TenantStatus::Active || $currentUser->status !== UserStatus::Active || ! $settings->enabled || ! in_array($settings->review_mode, [KycReviewMode::Manual, KycReviewMode::Automatic], true)) {
                    throw new DomainException('KYC_SUBMISSION_UNAVAILABLE', 'Identity verification submission is not currently available.', 403);
                }
                if (IdentityRecord::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists()) {
                    throw new DomainException('KYC_ALREADY_APPROVED', 'Your identity is already verified.');
                }

                if ($latest && $latest->review_status !== KycReviewStatus::ResubmissionRequired) {
                    throw new DomainException(
                        $latest->review_status === KycReviewStatus::Pending ? 'KYC_ALREADY_PENDING' : 'KYC_RESUBMISSION_NOT_ALLOWED',
                        $latest->review_status === KycReviewStatus::Pending ? 'Identity verification is already under review.' : 'A new submission is not available for this application.',
                    );
                }

                $application = new KycApplication;
                $application->forceFill([
                    'id' => $applicationId,
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'resubmission_of_id' => $latest?->id,
                    'document_type' => $documentType,
                    'document_country' => $country,
                    'identity_number_encrypted' => $protected['encrypted'],
                    'identity_hash' => $protected['hash'],
                    'front_object_key' => $frontKey,
                    'back_object_key' => $backKey,
                    'ocr_status' => KycOcrStatus::Succeeded,
                    'ocr_provider' => $provider->name(),
                    'ocr_reference' => $ocr->providerReference,
                    'ocr_result_encrypted' => app(KycDataCipher::class)->encrypt(json_encode(['candidate_identity_match' => 'MATCH'], JSON_THROW_ON_ERROR)),
                    'review_status' => KycReviewStatus::Pending,
                    'submitted_at' => now(),
                ])->save();
                $this->audit->record($tenant->id, 'USER', $user->id, 'KYC_APPLICATION_SUBMITTED', 'kyc_application', $applicationId, null, ['document_type' => $documentType->value, 'document_country' => $country], $requestId);
                if ($settings->review_mode === KycReviewMode::Automatic) {
                    $this->approve->executeAutomatic($tenant->id, $applicationId, $requestId);
                    $application->refresh();
                }

                return $application;
            });

            return $application;
        } catch (Throwable $exception) {
            foreach ($stored as $side => $objectKey) {
                try {
                    Storage::disk($disk)->delete($objectKey);
                } catch (Throwable $cleanupException) {
                    Log::warning('KYC document cleanup failed.', [
                        'tenant_id' => $tenant->id,
                        'kyc_application_id' => $applicationId,
                        'document_sequence' => $side,
                        'error_class' => $cleanupException::class,
                    ]);
                }
            }
            if ($exception instanceof QueryException && $exception->getCode() === '23505') {
                throw new DomainException('KYC_ALREADY_PENDING', 'Identity verification is already under review.');
            }

            throw $exception;
        }
    }
}
