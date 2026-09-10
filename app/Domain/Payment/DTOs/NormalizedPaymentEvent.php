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
        $type = strtoupper($this->eventType);

        return str_contains($type, 'REFUND') || str_contains($type, 'CHARGEBACK')
            || str_contains($type, 'REVERSAL') || str_contains($type, 'DISPUTE');
    }
}
