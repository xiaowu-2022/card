<?php

namespace App\Domain\Notification\Exceptions;

/** The provider may have accepted this non-idempotent request. Do not resend it. */
final class SmsDeliveryUnknown extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('SMS delivery could not be confirmed.');
    }
}
