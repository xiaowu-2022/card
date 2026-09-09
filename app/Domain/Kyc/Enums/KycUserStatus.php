<?php

namespace App\Domain\Kyc\Enums;

enum KycUserStatus: string
{
    case NotSubmitted = 'NOT_SUBMITTED';
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case ResubmissionRequired = 'RESUBMISSION_REQUIRED';
}
