<?php

namespace App\Infrastructure\Sms;

use App\Domain\Notification\Contracts\SmsVerificationSender;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;

final class UnavailableSmsVerificationSender implements SmsVerificationSender
{
    public function sendVerificationCode(Tenant $tenant, string $destination, string $code): void
    {
        throw new DomainException('SMS_TRANSPORT_UNAVAILABLE', 'Phone verification is not available in this environment.', 503);
    }

    public function sendExistingAccountNotice(Tenant $tenant, string $destination): void
    {
        throw new DomainException('SMS_TRANSPORT_UNAVAILABLE', 'Phone verification is not available in this environment.', 503);
    }
}
