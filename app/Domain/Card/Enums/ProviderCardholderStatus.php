<?php

namespace App\Domain\Card\Enums;

enum ProviderCardholderStatus: string
{
    case Submitting = 'SUBMITTING';
    case Pending = 'PENDING';
    case Ready = 'READY';
    case ActionRequired = 'ACTION_REQUIRED';
    case Rejected = 'REJECTED';
    case Disabled = 'DISABLED';
    case Unknown = 'UNKNOWN';
}
