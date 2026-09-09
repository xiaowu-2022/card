<?php

namespace App\Infrastructure\Providers\Kyc;

use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\Enums\KycOcrOutcome;

final readonly class MockKycOcrProvider implements KycOcrProviderInterface
{
    public function __construct(private string $mode = 'SUCCESS') {}

    public function name(): string
    {
        return 'mock';
    }

    public function extractIdentityDocument(KycOcrRequestDTO $request): KycOcrResultDTO
    {
        if ($this->mode === 'FAILED') {
            return new KycOcrResultDTO(KycOcrOutcome::Failed, providerReference: 'MOCK-FAILED');
        }
        if ($this->mode === 'TIMEOUT') {
            throw new \RuntimeException('Mock OCR timeout.');
        }

        return new KycOcrResultDTO(KycOcrOutcome::Success, 'MOCK-ID-0001', 'MOCK TEST USER', '0.92', 'MOCK-SUCCESS');
    }
}
