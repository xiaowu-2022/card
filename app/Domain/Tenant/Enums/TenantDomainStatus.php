<?php

namespace App\Domain\Tenant\Enums;

enum TenantDomainStatus: string
{
    case PendingVerification = 'PENDING_VERIFICATION';
    case Verified = 'VERIFIED';
    case Active = 'ACTIVE';
    case Failed = 'FAILED';
    case Disabled = 'DISABLED';
}
