<?php

namespace App\Domain\User\Enums;

enum RegistrationChallengeStatus: string
{
    case Pending = 'PENDING';
    case Verified = 'VERIFIED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';
    case Locked = 'LOCKED';
}
