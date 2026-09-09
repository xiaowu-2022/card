<?php

namespace App\Domain\Admin\Enums;

enum AdminUserStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
}
