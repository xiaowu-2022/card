<?php

namespace App\Domain\CardProvider\DTOs;

final readonly class ProviderCardQuoteDTO
{
    public function __construct(
        public string $requestId,
        public string $debitAmount,
        public string $arrivalAmount,
        public string $feeAmount,
    ) {}
}
