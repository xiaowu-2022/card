<?php

namespace App\Domain\CardProvider\DTOs;

final readonly class ProviderSensitiveCardDTO
{
    public function __construct(
        public string $displayPan,
        public string $displayCvv,
        public bool $isTest,
    ) {}
}
