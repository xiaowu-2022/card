<?php

namespace App\Domain\Admin\Enums;

enum InvitationStatus: string
{
    case Pending = 'PENDING';
    case Accepted = 'ACCEPTED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';
}
