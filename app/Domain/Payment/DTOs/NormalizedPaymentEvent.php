<?php

namespace App\Domain\Payment\DTOs;

use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;

final readonly class NormalizedPaymentEvent
{
    public function __construct(
        public string $eventKey,
        public string $eventType,
        public ?string $providerTransactionId,
        public ?string $providerRequestId,
        public ?PaymentProviderTransactionStatus $status,
        public ?string $amount,
        public ?string $assetCode,
        public string $payloadDigest,
    ) {}

    public function isRefund(): bool
    {
        return str_contains(strtoupper($this->eventType), 'REFUND') || str_contains(strtoupper($this->eventType), 'CHARGEBACK');
    }
}
