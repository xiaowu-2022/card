<?php

namespace App\Domain\Withdrawal\Enums;

enum BlockchainVerificationOutcome: string
{
    case Confirmed = 'CONFIRMED';
    case Pending = 'PENDING';
    case Failed = 'FAILED';
    case WrongDestination = 'WRONG_DESTINATION';
    case WrongAmount = 'WRONG_AMOUNT';
    case WrongToken = 'WRONG_TOKEN';
}
