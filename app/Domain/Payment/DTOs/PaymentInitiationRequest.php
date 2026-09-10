<?php

namespace App\Domain\Payment\DTOs;

final readonly class PaymentInitiationRequest
{
    public function __construct(
        public string $tenantId,
        public string $orderId,
        public string $providerRequestId,
        public string $amount,
        public string $assetCode,
        public string $returnUrl,
    ) {}
}
