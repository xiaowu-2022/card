<?php

namespace App\Application\Kyc;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Enums\KycOcrStatus;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Domain\Kyc\Services\NationalIdNumber;
use Illuminate\Validation\ValidationException;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\IdentityHashGenerator;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\Tenant\Enums\KycReviewMode;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ApproveKycAction
{
    public function __construct(private AuditLogger $audit, private IdentityHashGenerator $hashes) {}

    public function execute(string $tenantId, string $applicationId, AdminUser $reviewer, ?string $requestId = null, #[\SensitiveParameter] ?string $identityNumber = null): IdentityRecord
    {
        return $this->approve($tenantId, $applicationId, $reviewer, $requestId, $identityNumber);
    }

    /** Called by the explicit submission processor after successful OCR, never by a public approval endpoint. */
    public function executeAutomatic(string $tenantId, string $applicationId, ?string $requestId = null): IdentityRecord
    {
        return $this->approve($tenantId, $applicationId, null, $requestId);
    }

    private function approve(string $tenantId, string $applicationId, ?AdminUser $reviewer, ?string $requestId, #[\SensitiveParameter] ?string $identityNumber = null): IdentityRecord
    {
        try {
            return DB::transaction(function () use ($tenantId, $applicationId, $reviewer, $requestId, $identityNumber): IdentityRecord {
                Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
                $application = KycApplication::query()->where('tenant_id', $tenantId)->whereKey($applicationId)->lockForUpdate()->firstOrFail();
                if ($application->review_status !== KycReviewStatus::Pending || ($reviewer === null && $application->processing_status === 'FAILED')
                    || KycApplication::where('tenant_id', $tenantId)->where('resubmission_of_id', $application->id)->exists()) {
                    throw new DomainException('KYC_ALREADY_REVIEWED', 'This application has already been reviewed.', 409);
                }
                $manualNumber = false;
                if ($reviewer !== null && $application->document_type === KycDocumentType::NationalId) {
                    if ($application->identity_hash === null || ($identityNumber !== null && trim($identityNumber) !== '')) {
                        $number = app(NationalIdNumber::class)->normalize($identityNumber ?? '');
                        if ($number === null) {
                            throw ValidationException::withMessages(['identity_number' => 'Enter a valid identity number.']);
                        }
                        $protected = app(IdentityNumberProtector::class)->protect($tenantId, $application->document_type->value, $application->document_country, $number);
                        if ($application->identity_hash !== null) {
                            if (! hash_equals($application->identity_hash, $protected['hash'])) {
                                throw ValidationException::withMessages(['identity_number' => 'The existing identity number cannot be changed.']);
                            }
                        } else {
                            $application->forceFill(['identity_number_encrypted' => $protected['encrypted'],
                                'identity_hash' => $protected['hash'], 'identity_number_source' => 'ADMIN']);
                            $manualNumber = true;
                        }
                    }
                }
                // Automatic approval still requires successful encrypted OCR evidence.
                // Manual review records its own decision without inventing OCR or a number.
                if ($reviewer === null) {
                    $evidence = $application->ocr_result_encrypted ? json_decode(app(KycDataCipher::class)->decrypt($application->ocr_result_encrypted), true) : [];
                    if ($application->ocr_status !== KycOcrStatus::Succeeded || ! $application->identity_hash || ! $application->identity_number_encrypted
                        || (($evidence['candidate_identity_match'] ?? null) !== 'MATCH' && ! (($evidence['identity_number_source'] ?? null) === 'OCR' && ($evidence['identity_number_recognized'] ?? false) === true))) {
                        throw new DomainException('KYC_OCR_REQUIRED', 'Document recognition must succeed and match before approval.', 409);
                    }
                }
                $settings = PlatformKycSetting::current(true);
                if ($reviewer === null && (! $settings->enabled || $settings->review_mode !== KycReviewMode::Automatic)) {
                    throw new DomainException('KYC_SUBMISSION_UNAVAILABLE', 'Identity verification submission is not currently available.', 403);
                }
                $existing = IdentityRecord::where('tenant_id', $tenantId)->where('user_id', $application->user_id)->first();
                if ($application->identity_hash !== null) {
                    DB::select('SELECT pg_advisory_xact_lock(?)', [$this->hashes->advisoryLockKey($application->identity_hash)]);
                    $count = IdentityRecord::query()->where('tenant_id', $tenantId)->where('identity_hash', $application->identity_hash)->where('user_id', '<>', $application->user_id)->count();
                    if ($count >= $settings->max_accounts_per_identity) {
                        throw new DomainException('IDENTITY_ACCOUNT_LIMIT_REACHED', 'This identity has reached the Tenant account limit.', 409);
                    }
                }
                $basis = $reviewer ? 'MANUAL' : 'OCR';

                if ($existing) {
                    abort_unless($application->resubmission_of_id, 409);
                    // Explicit re-verification replaces only the current identity projection.
                    // Prior applications, cardholder snapshots and ledger records are retained.
                    DB::table('identity_records')->where('id', $existing->id)->where('tenant_id', $tenantId)
                        ->where('user_id', $application->user_id)->update([
                            'source_kyc_application_id' => $application->id, 'verification_basis' => $basis,
                            'document_type' => $application->document_type->value,
                            'document_country' => $application->document_country,
                            'identity_number_encrypted' => $application->identity_number_encrypted,
                            'identity_hash' => $application->identity_hash, 'verified_at' => now(), 'updated_at' => now(),
                        ]);
                    $this->audit->record($tenantId, $reviewer ? 'ADMIN' : 'SYSTEM', $reviewer?->id, 'KYC_IDENTITY_REBOUND', 'identity_record', $existing->id,
                        ['source_kyc_application_id' => $existing->source_kyc_application_id], ['source_kyc_application_id' => $application->id], $requestId);
                    $existing->refresh();
                }
                $identity = $existing;
                if (! $identity) {
                    $identity = new IdentityRecord;
                    $identity->forceFill([
                        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $application->user_id,
                        'source_kyc_application_id' => $application->id, 'verification_basis' => $basis, 'document_type' => $application->document_type,
                        'document_country' => $application->document_country, 'identity_number_encrypted' => $application->identity_number_encrypted,
                        'identity_hash' => $application->identity_hash, 'verified_at' => now(),
                    ])->save();
                }
                $application->forceFill([
                    'review_status' => KycReviewStatus::Approved, 'reviewed_by_admin_user_id' => $reviewer?->id,
                    'automatically_approved' => $reviewer === null,
                    'reviewed_at' => now(), 'review_reason_code' => null, 'review_message' => null,
                    'processing_status' => $application->processing_status ? 'COMPLETE' : null, 'next_processing_at' => null,
                ])->save();
                $this->audit->record($tenantId, $reviewer ? 'ADMIN' : 'SYSTEM', $reviewer?->id, 'KYC_APPLICATION_APPROVED', 'kyc_application', $application->id, ['review_status' => KycReviewStatus::Pending->value], ['review_status' => KycReviewStatus::Approved->value, 'review_mode' => $reviewer ? 'MANUAL' : 'AUTOMATIC', 'ocr_status' => $application->ocr_status->value, 'identity_number_available' => $application->identity_hash !== null, 'identity_number_supplied_by_admin' => $manualNumber], $requestId);

                return $identity;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw new DomainException('KYC_ALREADY_APPROVED', 'This user already has a verified identity.', 409);
            }

            throw $exception;
        }
    }
}
