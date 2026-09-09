<?php

namespace App\Domain\Wallet\Enums;

enum WalletStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Closed = 'CLOSED';
}
