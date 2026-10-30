<?php

namespace App\Domain\Kyc\DTOs;

final readonly class KycDocumentValidityDTO
{
    public function __construct(
        public string $validFrom,
        public ?string $validUntil,
        public bool $longTerm,
    ) {}
}
