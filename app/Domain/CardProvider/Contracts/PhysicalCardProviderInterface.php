<?php

namespace App\Domain\CardProvider\Contracts;

interface PhysicalCardProviderInterface
{
    public function addRecipient(#[\SensitiveParameter] array $fields): string;

    public function recipientAvailable(string $recipientId): bool;

    public function activatePhysicalCard(string $cardId, string $expiry, #[\SensitiveParameter] string $pin, #[\SensitiveParameter] string $confirmation): void;
}
