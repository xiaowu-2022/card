<?php

namespace App\Infrastructure\Providers\Domain;

use App\Domain\Tenant\Contracts\DomainVerificationService;
use Closure;

final readonly class DnsDomainVerificationService implements DomainVerificationService
{
    public function __construct(private ?Closure $lookup = null) {}

    public function verify(string $hostname, string $verificationToken): bool
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        if (strlen($hostname) > 253 || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $hostname)
            || ! preg_match('/^vc-verify-[a-f0-9]{32}$/D', $verificationToken)) {
            return false;
        }
        try {
            $records = ($this->lookup ?? static fn (string $name) => @dns_get_record($name, DNS_TXT))('_vc-verification.'.$hostname);
            foreach (is_array($records) ? $records : [] as $record) {
                if (($record['type'] ?? null) !== 'TXT') {
                    continue;
                }
                $value = $record['txt'] ?? null;
                if (is_string($value) && hash_equals($verificationToken, $value)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}
