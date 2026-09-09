<?php

namespace App\Domain\Tenant\Enums;

enum TenantSurfaceAccess: string
{
    case Allowed = 'ALLOWED';
    case Restricted = 'RESTRICTED';
    case Unavailable = 'UNAVAILABLE';
}
