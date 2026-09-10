<?php

namespace App\Domain\Payment\Enums;

enum PaymentProviderTransactionStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Unknown = 'UNKNOWN';
}
