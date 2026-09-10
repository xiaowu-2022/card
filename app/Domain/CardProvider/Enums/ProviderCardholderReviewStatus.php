<?php

namespace App\Domain\CardProvider\Enums;

enum ProviderCardholderReviewStatus: string
{
    case Pending = 'PENDING';
    case Ready = 'READY';
    case ActionRequired = 'ACTION_REQUIRED';
    case Rejected = 'REJECTED';
    case Disabled = 'DISABLED';
    case Unknown = 'UNKNOWN';
}
