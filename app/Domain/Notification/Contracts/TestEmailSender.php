<?php

namespace App\Domain\Notification\Contracts;

use App\Domain\Notification\DTOs\EmailConnection;
use App\Domain\Tenant\Models\Tenant;

interface TestEmailSender
{
    public function sendTest(Tenant $tenant, string $destination, EmailConnection $connection): void;
}
