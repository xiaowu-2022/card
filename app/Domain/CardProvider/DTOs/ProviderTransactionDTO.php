<?php

namespace App\Domain\CardProvider\DTOs;

use DateTimeImmutable;

final readonly class ProviderTransactionDTO
{
    public function __construct(
        public string $providerTransactionId,
        public string $amount,
        public string $assetCode,
        public string $type,
        public string $status,
        public DateTimeImmutable $occurredAt,
    ) {}
}
