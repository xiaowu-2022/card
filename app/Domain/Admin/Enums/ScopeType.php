<?php

namespace App\Domain\Admin\Enums;

enum ScopeType: string
{
    case Platform = 'PLATFORM';
    case Tenant = 'TENANT';
}
