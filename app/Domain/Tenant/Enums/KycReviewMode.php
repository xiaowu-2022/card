<?php

namespace App\Domain\Tenant\Enums;

enum KycReviewMode: string
{
    case Manual = 'MANUAL';
    case Automatic = 'AUTOMATIC';
    case ProviderAutomatic = 'PROVIDER_AUTOMATIC';
}
