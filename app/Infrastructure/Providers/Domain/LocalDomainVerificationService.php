<?php

namespace App\Infrastructure\Providers\Domain;

use App\Domain\Tenant\Contracts\DomainVerificationService;

final class LocalDomainVerificationService implements DomainVerificationService
{
    public function verify(string $hostname, string $verificationToken): bool
    {
        return in_array(strtolower($hostname), config('tenancy.local_verified_domains'), true)
            && str_starts_with($verificationToken, 'vc-verify-');
    }
}
