<?php

namespace App\Application\Kyc;

use App\Application\Inbox\InboxWriter;
use App\Application\Media\ImageStorage;
use App\Application\Media\ServerImages;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\Enums\KycOcrStatus;
use App\Domain\Kyc\Enums\KycReviewStatus;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\Tenant\Enums\KycReviewMode;
use App\Domain\Tenant\Models\PlatformKycSetting;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Domain\Tenant\TenantContext;
use App\Infrastructure\Storage\OssImages;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

final class ProcessPendingKyc
{
    public function execute(string $tenantId, string $id): void
    {
        $lock = Cache::lock('kyc-processing:'.$id, 600);
        if (! $lock->get()) return;
        $context = app(TenantContext::class);
        $previousTenant = $context->hasTenant() ? $context->tenant() : null;
        $application = null;
        $stage = 'storage';
        try {
            $application = DB::transaction(function () use ($tenantId, $id) {
                $row = KycApplication::where('tenant_id', $tenantId)->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($row->review_status !== KycReviewStatus::Pending || ! in_array($row->processing_status, ['QUEUED', 'PROCESSING'], true)
                    || $row->next_processing_at?->isFuture()) return null;
                $row->forceFill(['processing_status' => 'PROCESSING', 'processing_attempts' => $row->processing_attempts + 1,
                    'next_processing_at' => now()->addMinutes(10)])->save();
                return $row;
            });
            if (! $application) return;
            if ($application->processing_attempts > 3) throw new DomainException('KYC_PROCESSING_UNAVAILABLE', 'Processing attempts exhausted.', 503);
            $tenant = Tenant::findOrFail($tenantId);
            $context->set($tenant);
            if ($tenant->status->value !== 'ACTIVE' || $application->user->status->value !== 'ACTIVE' || ! PlatformKycSetting::current()->enabled) {
                throw new DomainException('KYC_SUBMISSION_UNAVAILABLE', 'Identity verification is unavailable.', 403);
            }
            $domain = TenantDomain::where('tenant_id', $tenantId)->where('status', 'ACTIVE')->orderBy('hostname')->firstOrFail();
            $scheme = str_ends_with($domain->hostname, '.localhost') ? 'http' : 'https';
            URL::forceRootUrl($scheme.'://'.$domain->hostname);
            URL::forceScheme($scheme);
            $images = app(ImageStorage::class);
            foreach (array_filter(['front' => $application->front_object_key, 'back' => $application->back_object_key]) as $side => $key) {
                $stage = $side.'_replica_read';
                $image = $images->record((string) config('kyc.document_disk'), $key);
                if (! $image || $image->tenant_id !== $tenantId || $image->state !== 'ready') {
                    throw new DomainException('KYC_DOCUMENT_STORAGE_FAILED', 'Saved document unavailable.', 503);
                }
                $imageLock = Cache::lock('image-replica:'.$image->id, 300);
                if (! $imageLock->get()) throw new DomainException('KYC_DOCUMENT_STORAGE_FAILED', 'Document replication in progress.', 503);
                try {
                    $bytes = app(ServerImages::class)->read($image);
                    $config = $images->active();
                    if (! $config && ! ServerImages::enabled()) throw new DomainException('KYC_DOCUMENT_STORAGE_FAILED', 'Storage configuration unavailable.', 503);
                    if ($config) {
                        $oss = app(OssImages::class);
                        $stage = $side.'_upload';
                        if ($image->oss_pending) $oss->put($config, $image->object_key, $bytes, $image->mime);
                        $stage = $side.'_readback';
                        $remote = $oss->getBounded($config, $image->object_key, strlen($bytes));
                        if (! hash_equals(hash('sha256', $bytes), hash('sha256', $remote))) {
                            throw new DomainException('KYC_DOCUMENT_INTEGRITY_FAILED', 'Document integrity check failed.', 503);
                        }
                        $image->update(['oss_pending' => false, 'last_error' => null]);
                    }
                } finally {
                    $imageLock->release();
                }
            }
            $stage = 'ocr';
            if ($application->ocr_status !== KycOcrStatus::Succeeded) {
                $result = app(RecognizeKycNumber::class)->execute($application->document_type, $application->document_country,
                    $images->ocrUrl((string) config('kyc.document_disk'), $application->front_object_key));
                $protected = app(IdentityNumberProtector::class)->protect($tenantId, $application->document_type->value, $application->document_country, $result->candidateIdentityNumber);
                DB::transaction(function () use ($application, $protected, $result) {
                    $row = KycApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
                    abort_unless($row->review_status === KycReviewStatus::Pending && $row->processing_generation === $application->processing_generation, 409);
                    $row->forceFill(['identity_number_encrypted' => $protected['encrypted'], 'identity_hash' => $protected['hash'], 'identity_number_source' => 'OCR',
                        'ocr_status' => KycOcrStatus::Succeeded, 'ocr_provider' => app(KycOcrProviderInterface::class)->name(),
                        'ocr_reference' => $result->providerReference,
                        'ocr_result_encrypted' => app(KycDataCipher::class)->encrypt(json_encode(['identity_number_source' => 'OCR', 'identity_number_recognized' => true], JSON_THROW_ON_ERROR))])->save();
                });
            }
            $stage = 'review';
            DB::transaction(function () use ($application, $tenantId) {
                Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
                $row = KycApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
                if ($row->review_status !== KycReviewStatus::Pending || $row->processing_generation !== $application->processing_generation) return;
                $settings = PlatformKycSetting::current(true);
                if (! $settings->enabled || $row->user->status->value !== 'ACTIVE' || $row->tenant->status->value !== 'ACTIVE') {
                    throw new DomainException('KYC_SUBMISSION_UNAVAILABLE', 'Identity verification is unavailable.', 403);
                }
                if ($settings->review_mode === KycReviewMode::Automatic) {
                    app(ApproveKycAction::class)->executeAutomatic($tenantId, $row->id);
                    $row->refresh();
                }
                $row->forceFill(['processing_status' => $row->review_status === KycReviewStatus::Approved ? 'COMPLETE' : 'WAITING_REVIEW',
                    'processing_error' => null, 'next_processing_at' => null])->save();
                app(AuditLogger::class)->record($tenantId, 'SYSTEM', null, 'KYC_PROCESSING_COMPLETED', 'kyc_application', $row->id, null,
                    ['generation' => $row->processing_generation, 'attempt' => $row->processing_attempts, 'status' => $row->processing_status], $row->submission_request_id);
            });
        } catch (\Throwable $error) {
            if (! $application) throw $error;
            $code = $error instanceof DomainException ? $error->errorCode : 'KYC_PROCESSING_UNAVAILABLE';
            $reason = $error instanceof DomainException ? ($error->details['reason'] ?? null) : null;
            if (! in_array($reason, ['permission', 'access_key', 'signature', 'clock', 'bucket', 'method', 'redirect', 'timeout', 'dns', 'tls', 'connect', 'unknown'], true)) $reason = null;
            DB::transaction(function () use ($application, $code, $stage, $error) {
                $row = KycApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
                if ($row->review_status !== KycReviewStatus::Pending || $row->processing_generation !== $application->processing_generation) return;
                $terminal = $row->processing_attempts >= 3 || ($error instanceof DomainException && $error->httpStatus < 500);
                $row->forceFill(['processing_status' => $terminal ? 'FAILED' : 'QUEUED', 'processing_error' => $code,
                    'ocr_status' => $row->ocr_status === KycOcrStatus::Succeeded ? KycOcrStatus::Succeeded : KycOcrStatus::Failed,
                    'next_processing_at' => $terminal ? null : now()->addSeconds($row->processing_attempts === 1 ? 60 : 180)])->save();
                app(AuditLogger::class)->record($row->tenant_id, 'SYSTEM', null, 'KYC_PROCESSING_FAILED', 'kyc_application', $row->id, null,
                    ['generation' => $row->processing_generation, 'attempt' => $row->processing_attempts, 'stage' => $stage, 'code' => $code, 'terminal' => $terminal], $row->submission_request_id);
                if ($terminal) app(InboxWriter::class)->record($row->tenant_id, $row->user_id,
                    'kyc_processing_failed:'.$row->id.':'.$row->processing_generation, 'kyc_processing_failed', [], '/kyc');
            });
            Log::warning('KYC background processing failed', ['request_id' => $application->submission_request_id, 'tenant_id' => $tenantId, 'kyc_application_id' => $id, 'stage' => $stage, 'code' => $code, 'reason' => $reason]);
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
            $previousTenant ? $context->set($previousTenant) : $context->clear();
            $lock->release();
        }
    }
}
