<?php

namespace App\Domain\Tenant\Contracts;

interface DomainVerificationService
{
    public function verify(string $hostname, string $verificationToken): bool;
}
