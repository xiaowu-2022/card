<?php

namespace App\Application\Kyc;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RetryKycProcessing
{
    public function execute(string $tenant, string $id, AdminUser $actor, string $requestId, string $reason): KycApplication
    {
        return DB::transaction(function () use ($tenant, $id, $actor, $requestId, $reason) {
            abort_unless(app(AuthorizationService::class)->allows($actor, ScopeType::Platform, null, 'kyc.review'), 403);
            Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $old = DB::table('kyc_processing_retries')->where('id', $requestId)->first();
            if ($old) {
                abort_unless($old->tenant_id === $tenant && $old->application_id === $id && $old->admin_user_id === $actor->id && $old->reason === $reason, 409);
                return KycApplication::where('tenant_id', $tenant)->findOrFail($old->result_application_id);
            }
            $row = KycApplication::where('tenant_id', $tenant)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(($row->processing_status === 'FAILED' && $row->review_status === KycReviewStatus::Pending)
                || ($row->processing_status !== null && $row->review_status === KycReviewStatus::Rejected), 409);
            abort_unless(KycApplication::where('tenant_id', $tenant)->where('user_id', $row->user_id)->latest('submitted_at')->first()->id === $row->id, 409);
            if ($row->review_status === KycReviewStatus::Rejected) {
                $previous = $row;
                $row = new KycApplication;
                $row->forceFill($previous->only(['tenant_id', 'user_id', 'document_type', 'document_country', 'identity_number_encrypted', 'identity_hash',
                    'front_object_key', 'back_object_key', 'ocr_status', 'ocr_provider', 'ocr_reference', 'ocr_result_encrypted']));
                $row->forceFill(['id' => (string) Str::uuid(), 'resubmission_of_id' => $previous->id, 'review_status' => KycReviewStatus::Pending,
                    'submitted_at' => now(), 'processing_generation' => 0]);
            }
            $row->forceFill(['processing_status' => 'QUEUED', 'processing_generation' => $row->processing_generation + 1,
                'processing_attempts' => 0, 'processing_error' => null, 'next_processing_at' => now()])->save();
            DB::table('kyc_processing_retries')->insert(['id' => $requestId, 'tenant_id' => $tenant, 'application_id' => $id, 'result_application_id' => $row->id,
                'admin_user_id' => $actor->id, 'reason' => $reason, 'generation' => $row->processing_generation, 'created_at' => now()]);
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor->id, 'KYC_PROCESSING_RETRIED', 'kyc_application', $id, null,
                ['generation' => $row->processing_generation, 'reason' => $reason], $requestId);
            return $row;
        });
    }
}
