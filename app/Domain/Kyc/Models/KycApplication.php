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
        'identity_number_encrypted', 'identity_hash', 'front_object_key', 'back_object_key', 'ocr_result_encrypted',
    ];

    protected static function booted(): void
    {
        self::deleting(fn () => throw new LogicException('KYC applications are immutable history and cannot be deleted.'));
        self::updating(function (self $application): void {
            $immutable = [
                'id', 'tenant_id', 'user_id', 'resubmission_of_id', 'document_type', 'document_country',
                'identity_number_encrypted', 'identity_hash', 'front_object_key', 'back_object_key', 'submitted_at',
            ];
            if ($application->isDirty($immutable)) {
                throw new LogicException('Submitted KYC identity data and documents are immutable.');
            }

            $reviewFields = ['review_status', 'review_reason_code', 'review_message', 'reviewed_by_admin_user_id', 'reviewed_at'];
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
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
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
