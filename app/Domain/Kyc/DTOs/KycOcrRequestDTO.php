<?php

namespace App\Domain\Kyc\DTOs;

use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Kyc\Enums\KycOcrSide;

final readonly class KycOcrRequestDTO
{
    public function __construct(
        public KycDocumentType $documentType,
        public string $documentCountry,
        public string $frontUrl,
        public string $backUrl,
        public KycOcrSide $side = KycOcrSide::Front,
    ) {}
}
