<?php

namespace App\Domain\Withdrawal\Enums;

enum WithdrawalDestinationStatus: string
{
    case Active = 'ACTIVE';
    case Disabled = 'DISABLED';
}
