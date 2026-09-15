<?php

namespace App\Domain\CardProvider\DTOs;

final readonly class CardholderUpdateDTO
{
    /** @param array<string,string> $fields */
    public function __construct(public string $holderId, #[\SensitiveParameter] public array $fields) {}
}
