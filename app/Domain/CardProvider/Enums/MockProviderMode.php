<?php

namespace App\Domain\CardProvider\Enums;

enum MockProviderMode: string
{
    case Success = 'SUCCESS';
    case Failed = 'FAILED';
    case Timeout = 'TIMEOUT';
    case Unknown = 'UNKNOWN';
    case DelayedSuccess = 'DELAYED_SUCCESS';
    case DelayedFailure = 'DELAYED_FAILURE';
    case DuplicateWebhook = 'DUPLICATE_WEBHOOK';
    case RateLimit = 'RATE_LIMIT';
}
