<?php

namespace App\Domain\User\Services;

use App\Support\Errors\DomainException;

final class EmailNormalizer
{
    public function normalize(string $email): string
    {
        $normalized = strtolower(trim($email));
        if (! filter_var($normalized, FILTER_VALIDATE_EMAIL) || strlen($normalized) > 255) {
            throw new DomainException('INVALID_EMAIL', 'Enter a valid email address.', 422);
        }

        return $normalized;
    }
}
