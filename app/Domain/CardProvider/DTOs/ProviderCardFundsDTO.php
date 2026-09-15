<?php

namespace App\Domain\CardProvider\DTOs;

use App\Domain\CardProvider\Enums\ProviderOperationStatus;

final readonly class ProviderCardFundsDTO
{
    public function __construct(
        public ProviderOperationStatus $status,
        public string $cardId,
        public string $requestId,
        public ?string $transactionId = null,
        public ?string $debitAmount = null,
        public ?string $arrivalAmount = null,
        public ?string $feeAmount = null,
    ) {}
}
