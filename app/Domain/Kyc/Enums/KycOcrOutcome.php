<?php

namespace App\Domain\Kyc\Enums;

enum KycOcrOutcome: string
{
    case Success = 'SUCCESS';
    case Failed = 'FAILED';
}
