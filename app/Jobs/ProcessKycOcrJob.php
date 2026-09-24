<?php

namespace App\Jobs;

use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Kyc\Enums\KycOcrStatus;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\IdentityNumberNormalizer;
use App\Domain\Kyc\Services\IdentityNumberProtector;
use App\Domain\Kyc\Services\KycDataCipher;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ProcessKycOcrJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $tenantId, public readonly string $kycApplicationId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("kyc-ocr:{$this->tenantId}:{$this->kycApplicationId}"))->expireAfter(120)];
    }

    public function handle(
        TenantContext $context,
        KycOcrProviderInterface $provider,
        IdentityNumberNormalizer $normalizer,
        IdentityNumberProtector $identities,
        KycDataCipher $cipher,
    ): void {
        $tenant = Tenant::query()->whereKey($this->tenantId)->firstOrFail();
        $context->set($tenant);
        try {
            $application = KycApplication::query()->where('tenant_id', $this->tenantId)->whereKey($this->kycApplicationId)->firstOrFail();
            if ($application->ocr_status === KycOcrStatus::Succeeded) {
                return;
            }
            $application->forceFill(['ocr_status' => KycOcrStatus::Processing])->save();
            try {
                $disk = Storage::disk((string) config('kyc.document_disk'));
                $result = $provider->extractIdentityDocument(new KycOcrRequestDTO(
                    $application->document_type,
                    $application->document_country,
                    (string) $disk->get($application->front_object_key),
                    $application->back_object_key ? (string) $disk->get($application->back_object_key) : '',
                ));
            } catch (Throwable) {
                throw new \RuntimeException('KYC OCR processing failed.');
            }

            if ($result->outcome === KycOcrOutcome::Failed) {
                $application->forceFill(['ocr_status' => KycOcrStatus::Failed, 'ocr_provider' => $provider->name(), 'ocr_reference' => $this->bounded($result->providerReference, 255), 'ocr_result_encrypted' => null])->save();

                return;
            }
            $payload = [
                'candidate_identity_match' => $this->identityMatch($result->candidateIdentityNumber, $application->identity_number_encrypted, $normalizer, $identities),
                'candidate_name' => $this->bounded($result->candidateName, 200),
                'confidence' => preg_match('/^(0(\.\d{1,4})?|1(\.0{1,4})?)$/', (string) $result->confidence) ? $result->confidence : null,
            ];
            $application->forceFill([
                'ocr_status' => KycOcrStatus::Succeeded,
                'ocr_provider' => $provider->name(),
                'ocr_reference' => $this->bounded($result->providerReference, 255),
                'ocr_result_encrypted' => $cipher->encrypt(json_encode($payload, JSON_THROW_ON_ERROR)),
            ])->save();
        } finally {
            $context->clear();
        }
    }

    public function failed(?Throwable $exception): void
    {
        KycApplication::query()->where('tenant_id', $this->tenantId)->whereKey($this->kycApplicationId)
            ->where('ocr_status', '!=', KycOcrStatus::Succeeded->value)
            ->update(['ocr_status' => KycOcrStatus::Failed->value]);
    }

    private function bounded(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr(trim(strip_tags($value)), 0, $length);
    }

    private function identityMatch(?string $candidate, string $encryptedIdentity, IdentityNumberNormalizer $normalizer, IdentityNumberProtector $identities): string
    {
        if ($candidate === null || trim($candidate) === '') {
            return 'UNKNOWN';
        }

        try {
            return hash_equals($identities->decrypt($encryptedIdentity), $normalizer->normalize($candidate)) ? 'MATCH' : 'MISMATCH';
        } catch (Throwable) {
            return 'UNKNOWN';
        }
    }
}
