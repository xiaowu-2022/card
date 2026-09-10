<?php

namespace App\Domain\Payment\DTOs;

use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;

final readonly class PaymentProviderResult
{
    public function __construct(
        public PaymentProviderTransactionStatus $status,
        public string $providerRequestId,
        public ?string $providerTransactionId,
        public string $amount,
        public string $assetCode,
        public ?string $checkoutUrl = null,
    ) {}
}
