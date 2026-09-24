<?php

namespace App\Domain\CardProvider\DTOs;

final readonly class ProviderCardDTO
{
    public function __construct(
        public string $providerCardId,
        public string $providerCardToken,
        public string $maskedPan,
        public string $last4,
        public ?int $expiryMonth,
        public ?int $expiryYear,
        public string $assetCode,
        public string $status,
        public bool $isTest,
        public ?string $providerBalance = null,
        public string $formFactor = 'virtual_card',
        public ?string $produceStatus = null,
        public ?string $trackingNumber = null,
    ) {}
}
