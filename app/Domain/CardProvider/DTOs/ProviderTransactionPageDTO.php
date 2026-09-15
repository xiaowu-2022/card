<?php

namespace App\Domain\CardProvider\DTOs;

final readonly class ProviderTransactionPageDTO
{
    /** @param list<ProviderCardTransactionDTO> $items */
    public function __construct(public array $items, public int $page, public bool $hasMore) {}
}
