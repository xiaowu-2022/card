<?php

namespace App\Infrastructure\Providers\Kyc;

use App\Domain\Kyc\Contracts\KycOcrProviderInterface;
use App\Domain\Kyc\DTOs\KycOcrRequestDTO;
use App\Domain\Kyc\DTOs\KycOcrResultDTO;
use App\Domain\Kyc\DTOs\KycDocumentValidityDTO;
use App\Domain\Kyc\Enums\KycOcrOutcome;
use App\Domain\Kyc\Enums\KycOcrSide;

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
        if ($request->side === KycOcrSide::Back) {
            return new KycOcrResultDTO(KycOcrOutcome::Success, providerReference: 'MOCK-SUCCESS',
                validity: new KycDocumentValidityDTO('2020-01-01', null, true));
        }

        return new KycOcrResultDTO(KycOcrOutcome::Success, 'MOCK-ID-0001', 'MOCK TEST USER', '0.92', 'MOCK-SUCCESS');
    }
}
