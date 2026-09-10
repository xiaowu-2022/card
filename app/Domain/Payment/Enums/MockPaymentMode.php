<?php

namespace App\Domain\Payment\Enums;

enum MockPaymentMode: string
{
    case Pending = 'PENDING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Timeout = 'TIMEOUT';
    case Unknown = 'UNKNOWN';
}
