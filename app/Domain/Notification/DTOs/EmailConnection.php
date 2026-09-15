<?php

namespace App\Domain\Notification\DTOs;

final readonly class EmailConnection
{
    public function __construct(public string $fromAddress, public string $fromName, #[\SensitiveParameter] public string $token) {}
}
