<?php

namespace App\Domain\CardProduct\Enums;

enum TenantCardProductStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
