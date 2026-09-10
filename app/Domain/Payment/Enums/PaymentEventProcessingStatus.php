<?php

namespace App\Domain\Payment\Enums;

enum PaymentEventProcessingStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Processed = 'PROCESSED';
    case Failed = 'FAILED';
    case RequiresReview = 'REQUIRES_REVIEW';
}
