<?php

namespace App\Domain\Admin\Enums;

enum MembershipStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Revoked = 'REVOKED';
}
