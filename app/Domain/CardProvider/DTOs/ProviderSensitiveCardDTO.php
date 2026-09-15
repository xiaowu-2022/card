<?php

namespace App\Domain\CardProvider\DTOs;

final readonly class ProviderSensitiveCardDTO
{
    public function __construct(
        #[\SensitiveParameter] public string $displayPan,
        #[\SensitiveParameter] public string $displayCvv,
        public bool $isTest,
        public ?string $expiry = null,
    ) {}
}
