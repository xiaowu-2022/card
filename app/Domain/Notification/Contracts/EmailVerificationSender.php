<?php

namespace App\Domain\Notification\Contracts;

use App\Domain\Tenant\Models\Tenant;

interface EmailVerificationSender
{
    public function isAvailable(Tenant $tenant): bool;

    public function sendVerificationCode(Tenant $tenant, string $destination, string $code): void;

    public function sendExistingAccountNotice(Tenant $tenant, string $destination): void;
}
