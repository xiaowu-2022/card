<?php

namespace App\Domain\User\Services;

final readonly class OtpHasher
{
    public function hash(string $challengeId, string $code): string
    {
        return hash_hmac('sha256', $challengeId.':'.$code, (string) config('user-auth.otp_secret'));
    }

    public function verify(string $challengeId, string $code, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->hash($challengeId, $code));
    }
}
