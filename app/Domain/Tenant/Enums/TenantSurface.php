<?php

namespace App\Domain\Tenant\Enums;

enum TenantSurface: string
{
    case EndUser = 'end-user';
    case TenantAdmin = 'tenant-admin';
}
