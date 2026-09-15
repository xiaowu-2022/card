<?php

namespace App\Domain\Notification\Exceptions;

final class EmailDeliveryUnknown extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Email delivery could not be confirmed.');
    }
}
