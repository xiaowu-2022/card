<?php

namespace App\Domain\CardProvider\DTOs;

use App\Domain\CardProvider\Enums\ProviderCardholderReviewStatus;

final readonly class ProviderCardholderDTO
{
    public function __construct(
        public ?string $providerCardholderId,
        public ProviderCardholderReviewStatus $status,
        public ?string $providerStatus = null,
        public ?string $providerReviewStatus = null,
        public ?string $safeReason = null,
    ) {}
}
