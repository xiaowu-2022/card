<?php

namespace App\Domain\User\Enums;

enum RegistrationChannel: string
{
    case Email = 'EMAIL';
    case Phone = 'PHONE';
}
