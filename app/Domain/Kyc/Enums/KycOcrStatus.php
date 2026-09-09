<?php

namespace App\Domain\Kyc\Enums;

enum KycOcrStatus: string
{
    case NotStarted = 'NOT_STARTED';
    case Processing = 'PROCESSING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
}
