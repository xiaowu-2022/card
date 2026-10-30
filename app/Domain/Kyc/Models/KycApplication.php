<?php

namespace App\Domain\Kyc\Models;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycOcrStatus;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class KycApplication extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = [
        'identity_number_encrypted', 'identity_hash', 'front_object_key', 'back_object_key', 'ocr_result_encrypted', 'back_ocr_result_encrypted',
    ];

    protected static function booted(): void
    {
        self::deleting(fn () => throw new LogicException('KYC applications are immutable history and cannot be deleted.'));
        self::creating(function (self $application): void {
            if ($application->back_ocr_result_encrypted === null) {
                return;
            }
            $source = $application->resubmission_of_id ? self::query()->whereKey($application->resubmission_of_id)
                ->where('tenant_id', $application->tenant_id)->where('user_id', $application->user_id)->first() : null;
            if ($application->document_type !== KycDocumentType::NationalId || ! $application->back_object_key
                || $application->review_status !== KycReviewStatus::Pending || $application->processing_status !== 'QUEUED'
                || $application->processing_generation < 1 || ! $source || $source->review_status !== KycReviewStatus::Rejected
                || $source->document_type !== $application->document_type || $source->document_country !== $application->document_country
                || $source->back_object_key !== $application->back_object_key
                || $source->back_ocr_result_encrypted !== $application->back_ocr_result_encrypted) {
                throw new LogicException('Retained back-side OCR evidence may only be copied from a rejected application.');
            }
        });
        self::updating(function (self $application): void {
            $immutable = [
                'id', 'tenant_id', 'user_id', 'resubmission_of_id', 'document_type', 'document_country',
                'front_object_key', 'back_object_key', 'submitted_at', 'submission_key', 'submission_fingerprint',
            ];
            if ($application->isDirty($immutable)) {
                throw new LogicException('Submitted KYC identity data and documents are immutable.');
            }
            if ($application->isDirty('back_ocr_result_encrypted')
                && ($application->getRawOriginal('back_ocr_result_encrypted') !== null || ! $application->back_ocr_result_encrypted
                    || $application->getRawOriginal('review_status') !== KycReviewStatus::Pending->value || $application->review_status !== KycReviewStatus::Pending
                    || $application->document_type !== KycDocumentType::NationalId || ! $application->back_object_key
                    || $application->getRawOriginal('processing_status') !== 'PROCESSING' || $application->processing_status !== 'PROCESSING'
                    || $application->processing_generation < 1 || $application->isDirty('processing_generation'))) {
                throw new LogicException('Back-side OCR evidence may only be recorded once during pending processing.');
            }
            if ($application->isDirty(['identity_number_encrypted', 'identity_hash', 'identity_number_source'])) {
                $manualApproval = $application->identity_number_source === 'ADMIN'
                    && $application->review_status === KycReviewStatus::Approved
                    && $application->reviewed_by_admin_user_id !== null && ! $application->automatically_approved;
                $recognized = $application->ocr_status === KycOcrStatus::Succeeded && $application->ocr_result_encrypted
                    && $application->identity_number_source !== 'ADMIN';
                if ($application->getRawOriginal('identity_hash') !== null || $application->getRawOriginal('identity_number_encrypted') !== null
                    || $application->getRawOriginal('identity_number_source') !== null
                    || ! $application->getRawOriginal('processing_status') || $application->getRawOriginal('review_status') !== 'PENDING'
                    || ! $application->identity_hash || ! $application->identity_number_encrypted || (! $manualApproval && ! $recognized)) {
                    throw new LogicException('Recorded KYC identity is immutable.');
                }
            }

            $reviewFields = ['review_status', 'review_reason_code', 'review_message', 'reviewed_by_admin_user_id', 'reviewed_at', 'automatically_approved'];
            if ($application->isDirty($reviewFields) && $application->getRawOriginal('review_status') !== KycReviewStatus::Pending->value) {
                throw new LogicException('Reviewed KYC applications are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'document_type' => KycDocumentType::class,
            'ocr_status' => KycOcrStatus::class,
            'review_status' => KycReviewStatus::class,
            'automatically_approved' => 'boolean',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'next_processing_at' => 'immutable_datetime',
            'processing_generation' => 'integer',
            'processing_attempts' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by_admin_user_id');
    }

    public function resubmissionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resubmission_of_id');
    }
}
