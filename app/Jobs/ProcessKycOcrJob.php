<?php

namespace App\Jobs;

use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Kyc\Enums\KycOcrStatus;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Crypt;
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

    public function handle(TenantContext $context, KycOcrProviderInterface $provider): void
    {
        $tenant = Tenant::query()->whereKey($this->tenantId)->firstOrFail();
        $context->set($tenant);
        try {
            $application = KycApplication::query()->where('tenant_id', $this->tenantId)->whereKey($this->kycApplicationId)->firstOrFail();
            if ($application->ocr_status === KycOcrStatus::Succeeded) {
                return;
            }
            $application->forceFill(['ocr_status' => KycOcrStatus::Processing])->save();
            $disk = Storage::disk((string) config('kyc.document_disk'));
            $result = $provider->extractIdentityDocument(new KycOcrRequestDTO(
                $application->document_type,
                $application->document_country,
                (string) $disk->get($application->front_object_key),
                (string) $disk->get($application->back_object_key),
            ));

            if ($result->outcome === KycOcrOutcome::Failed) {
                $application->forceFill(['ocr_status' => KycOcrStatus::Failed, 'ocr_provider' => $provider->name(), 'ocr_reference' => $this->bounded($result->providerReference, 255), 'ocr_result_encrypted' => null])->save();

                return;
            }
            $payload = [
                'candidate_identity_number' => $this->bounded($result->candidateIdentityNumber, 128),
                'candidate_name' => $this->bounded($result->candidateName, 200),
                'confidence' => preg_match('/^(0(\.\d{1,4})?|1(\.0{1,4})?)$/', (string) $result->confidence) ? $result->confidence : null,
            ];
            $application->forceFill([
                'ocr_status' => KycOcrStatus::Succeeded,
                'ocr_provider' => $provider->name(),
                'ocr_reference' => $this->bounded($result->providerReference, 255),
                'ocr_result_encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            ])->save();
        } catch (Throwable $exception) {
            KycApplication::query()->where('tenant_id', $this->tenantId)->whereKey($this->kycApplicationId)
                ->update(['ocr_status' => KycOcrStatus::Failed->value]);
        } finally {
            $context->clear();
        }
    }

    private function bounded(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr(trim(strip_tags($value)), 0, $length);
    }
}
