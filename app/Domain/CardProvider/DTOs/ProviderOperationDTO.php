<?php

namespace App\Domain\CardProvider\DTOs;

use App\Domain\CardProvider\Enums\ProviderOperationStatus;

final readonly class ProviderOperationDTO
{
    public function __construct(
        public string $providerOperationId,
        public ProviderOperationStatus $status,
        public ?string $resourceId = null,
        public ?string $message = null,
        public ?ProviderCardDTO $card = null,
    ) {}
}
