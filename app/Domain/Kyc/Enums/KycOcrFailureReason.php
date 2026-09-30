<?php

namespace App\Domain\Kyc\Enums;

enum KycOcrFailureReason: string
{
    case FrontSide = 'FRONT_SIDE';
    case NumberMissing = 'NUMBER_MISSING';
    case NumberFormat = 'NUMBER_FORMAT';
    case NumberChecksum = 'NUMBER_CHECKSUM';
    case BackSide = 'BACK_SIDE';
    case BackFields = 'BACK_FIELDS';
}
