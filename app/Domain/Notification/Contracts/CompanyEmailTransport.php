<?php

namespace App\Domain\Notification\Contracts;

use App\Domain\Notification\DTOs\EmailConnection;

interface CompanyEmailTransport
{
    public function send(EmailConnection $connection, string $destination, string $subject, string $html): void;
}
