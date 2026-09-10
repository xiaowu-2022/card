<?php

namespace App\Domain\Withdrawal\Enums;

enum WithdrawalStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Verifying = 'VERIFYING';
    case Succeeded = 'SUCCEEDED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';
}
