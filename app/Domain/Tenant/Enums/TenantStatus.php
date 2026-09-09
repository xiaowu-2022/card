<?php

namespace App\Domain\Tenant\Enums;

enum TenantStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Closed = 'CLOSED';
}
