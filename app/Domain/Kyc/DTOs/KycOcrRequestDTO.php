<?php

namespace App\Domain\Kyc\DTOs;

use App\Domain\Kyc\Enums\KycDocumentType;

final readonly class KycOcrRequestDTO
{
    public function __construct(
        public KycDocumentType $documentType,
        public string $documentCountry,
        public string $frontUrl,
        public string $backUrl,
    ) {}
}
