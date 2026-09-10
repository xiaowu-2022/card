<?php

namespace App\Domain\Payment\Enums;

enum WalletTopupStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Paid = 'PAID';
    case Credited = 'CREDITED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
    case Refunded = 'REFUNDED';
}
