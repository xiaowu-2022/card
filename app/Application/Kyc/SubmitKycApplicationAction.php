<?php

namespace App\Application\Kyc;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycOcrStatus;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Domain\Tenant\Enums\KycReviewMode;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Jobs\ProcessKycOcrJob;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class SubmitKycApplicationAction
{
    public function __construct(private IdentityNumberProtector $identities, private AuditLogger $audit) {}

    public function execute(Tenant $tenant, User $user, string $country, string $identityNumber, UploadedFile $front, UploadedFile $back, ?string $requestId = null): KycApplication
    {
        $country = strtoupper(trim($country));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new DomainException('DOCUMENT_COUNTRY_INVALID', 'Choose a valid ISO country code.');
        }
        $applicationId = (string) Str::uuid();
        $disk = (string) config('kyc.document_disk');
        $base = "kyc/{$tenant->id}/{$user->id}/{$applicationId}";
        $frontKey = "{$base}/front/".(string) Str::uuid();
        $backKey = "{$base}/back/".(string) Str::uuid();
        $stored = [];

        try {
            if (! Storage::disk($disk)->putFileAs(dirname($frontKey), $front, basename($frontKey))) {
                throw new DomainException('KYC_DOCUMENT_STORAGE_FAILED', 'The documents could not be stored. Please try again.', 503);
            }
            $stored[] = $frontKey;
            if (! Storage::disk($disk)->putFileAs(dirname($backKey), $back, basename($backKey))) {
                throw new DomainException('KYC_DOCUMENT_STORAGE_FAILED', 'The documents could not be stored. Please try again.', 503);
            }
            $stored[] = $backKey;
            $protected = $this->identities->protect($tenant->id, $identityNumber);

            $application = DB::transaction(function () use ($tenant, $user, $country, $applicationId, $frontKey, $backKey, $protected, $requestId): KycApplication {
                $currentTenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $currentUser = User::query()->where('tenant_id', $tenant->id)->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $settings = $currentTenant->kycSettings()->lockForUpdate()->firstOrFail();
                if ($currentTenant->status !== TenantStatus::Active || $currentUser->status !== UserStatus::Active || ! $settings->enabled || $settings->review_mode !== KycReviewMode::Manual) {
                    throw new DomainException('KYC_SUBMISSION_UNAVAILABLE', 'Identity verification submission is not currently available.', 403);
                }
                if (IdentityRecord::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists()) {
                    throw new DomainException('KYC_ALREADY_APPROVED', 'Your identity is already verified.');
                }

                $latest = KycApplication::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->latest('submitted_at')->lockForUpdate()->first();
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
                    'document_type' => KycDocumentType::NationalId,
                    'document_country' => $country,
                    'identity_number_encrypted' => $protected['encrypted'],
                    'identity_hash' => $protected['hash'],
                    'front_object_key' => $frontKey,
                    'back_object_key' => $backKey,
                    'ocr_status' => KycOcrStatus::NotStarted,
                    'review_status' => KycReviewStatus::Pending,
                    'submitted_at' => now(),
                ])->save();
                $this->audit->record($tenant->id, 'USER', $user->id, 'KYC_APPLICATION_SUBMITTED', 'kyc_application', $applicationId, null, ['document_type' => KycDocumentType::NationalId->value, 'document_country' => $country], $requestId);
                ProcessKycOcrJob::dispatch($tenant->id, $applicationId)->afterCommit();

                return $application;
            });

            return $application;
        } catch (Throwable $exception) {
            foreach ($stored as $objectKey) {
                Storage::disk($disk)->delete($objectKey);
            }
            if ($exception instanceof QueryException && $exception->getCode() === '23505') {
                throw new DomainException('KYC_ALREADY_PENDING', 'Identity verification is already under review.');
            }

            throw $exception;
        }
    }
}
