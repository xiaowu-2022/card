<?php

namespace App\Application\User\DTOs;

use App\Domain\User\Models\RegistrationChallenge;

final readonly class CreatedRegistrationChallenge
{
    public function __construct(
        public RegistrationChallenge $challenge,
        public ?string $rawCode,
        public bool $existingAccount = false,
        public bool $reusedVerified = false,
    ) {}
}
