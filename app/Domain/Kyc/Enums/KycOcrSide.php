<?php

namespace App\Domain\Kyc\Enums;

enum KycOcrSide: string
{
    case Front = 'FRONT';
    case Back = 'BACK';
}
