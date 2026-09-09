<?php

namespace App\Domain\CardProvider\DTOs;

final readonly class ProviderBalanceDTO
{
    public function __construct(public string $amount, public string $assetCode) {}
}
