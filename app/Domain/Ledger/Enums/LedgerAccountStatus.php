<?php

namespace App\Domain\Ledger\Enums;

enum LedgerAccountStatus: string
{
    case Active = 'ACTIVE';
    case Closed = 'CLOSED';
}
