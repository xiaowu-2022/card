<?php

namespace App\Domain\Tenant\Enums;

enum TenantSurface: string
{
    case EndUser = 'end-user';
    case UserAuth = 'user-auth';
    case UserRestricted = 'user-restricted';
    case TenantAdmin = 'tenant-admin';
}
