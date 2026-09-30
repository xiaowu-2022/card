<?php

namespace App\Domain\Kyc\DTOs;

use App\Domain\Kyc\Enums\KycOcrFailureReason;
use App\Domain\Kyc\Enums\KycOcrOutcome;

final readonly class KycOcrResultDTO
{
    public function __construct(
        public KycOcrOutcome $outcome,
        public ?string $candidateIdentityNumber = null,
        public ?string $candidateName = null,
        public ?string $confidence = null,
        public ?string $providerReference = null,
        public ?KycOcrFailureReason $failureReason = null,
    ) {}
}
