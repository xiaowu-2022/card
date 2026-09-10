<?php

namespace App\Domain\CardProvider\DTOs;

final readonly class IssueCardRequestDTO
{
    public function __construct(
        public string $providerProductReference,
        public string $holderReference,
        public string $cardCurrency,
        public string $initialLoadAmount,
        public string $idempotencyKey,
    ) {}
}
