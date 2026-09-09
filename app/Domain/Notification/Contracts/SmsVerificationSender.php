<?php

namespace App\Domain\Notification\Contracts;

use App\Domain\Tenant\Models\Tenant;

interface SmsVerificationSender
{
    public function isAvailable(): bool;

    public function sendVerificationCode(Tenant $tenant, string $destination, string $code): void;

    public function sendExistingAccountNotice(Tenant $tenant, string $destination): void;
}
