<?php

namespace App\Domain\Kyc\Enums;

enum KycDocumentType: string
{
    case Passport = 'PASSPORT';
    case NationalId = 'NATIONAL_ID';
}
