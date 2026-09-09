<?php

namespace App\Domain\CardProvider\DTOs;

final readonly class ProviderCardDTO
{
    public function __construct(
        public string $providerCardId,
        public string $providerCardToken,
        public string $maskedPan,
        public string $last4,
        public int $expiryMonth,
        public int $expiryYear,
        public string $assetCode,
        public string $status,
        public bool $isTest,
    ) {}
}
