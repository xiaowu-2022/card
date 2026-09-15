<?php

namespace App\Application\Notification\DTOs;

final readonly class UpdateTenantEmailSettings
{
    public function __construct(public bool $enabled, public string $fromAddress, public string $fromName, #[\SensitiveParameter] public ?string $smtpToken, public int $dailyRecipientLimit) {}
}
