<?php

namespace App\Domain\Tenant\Services;

use App\Support\Errors\DomainException;

final class HostnameNormalizer
{
    public function normalize(string $hostname): string
    {
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        if ($hostname === ''
            || str_contains($hostname, '://')
            || preg_match('/[\/:?#@]/', $hostname)
            || strlen($hostname) > 253
            || ! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $hostname)) {
            throw new DomainException('INVALID_HOSTNAME', 'Enter a hostname without scheme, path, query, or port.');
        }

        if ($hostname === strtolower((string) config('tenancy.platform_admin_host'))) {
            throw new DomainException('RESERVED_HOSTNAME', 'This hostname is reserved by the Platform.');
        }

        return $hostname;
    }
}
