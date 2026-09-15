<?php

namespace App\Domain\CardProvider\DTOs;

final readonly class ProviderCardTransactionDTO
{
    public function __construct(
        public string $providerTransactionId,
        public string $amount,
        public string $currency,
        public string $type,
        public string $state,
        public string $occurredAt,
        public ?string $merchant,
    ) {}
}
