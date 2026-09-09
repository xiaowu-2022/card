<?php

namespace App\Domain\Ledger\DTOs;

use App\Domain\Ledger\ValueObjects\Money;

final readonly class LedgerPostingInstruction
{
    public function __construct(public string $accountId, public Money $delta) {}
}
