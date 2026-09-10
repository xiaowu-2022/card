<?php

namespace App\Domain\CardProduct\Enums;

enum CardProductStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
