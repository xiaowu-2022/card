<?php

namespace App\Infrastructure\Providers\Kyc;

use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycOcrOutcome;

final class UnavailableKycOcrProvider implements KycOcrProviderInterface
{
    public function name(): string
    {
        return 'unavailable';
    }

    public function extractIdentityDocument(KycOcrRequestDTO $request): KycOcrResultDTO
    {
        return new KycOcrResultDTO(KycOcrOutcome::Failed);
    }
}
