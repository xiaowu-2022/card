<?php

namespace App\Domain\Tenant\Enums;

enum KycReviewMode: string
{
    case Manual = 'MANUAL';
    case ProviderAutomatic = 'PROVIDER_AUTOMATIC';
}
