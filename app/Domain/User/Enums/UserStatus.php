<?php

namespace App\Domain\User\Enums;

enum UserStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Disabled = 'DISABLED';
}
