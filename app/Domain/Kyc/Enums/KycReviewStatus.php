<?php

namespace App\Domain\Kyc\Enums;

enum KycReviewStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case ResubmissionRequired = 'RESUBMISSION_REQUIRED';
}
