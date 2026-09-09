<?php

namespace App\Application\Admin;

use App\Domain\Tenant\Models\Tenant;

final class TenantAdminUrlGenerator
{
    public function invitation(Tenant $tenant, string $rawToken): string
    {
        $hostname = $tenant->domains()->where('is_primary', true)->value('hostname')
            ?? $tenant->domains()->where('domain_type', 'SYSTEM_SUBDOMAIN')->value('hostname');

        if (! is_string($hostname)) {
            throw new \LogicException('Tenant has no usable invitation domain.');
        }

        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($appUrl, PHP_URL_PORT);
        $authority = $hostname.($port ? ':'.$port : '');

        return $scheme.'://'.$authority.'/admin/invitations/'.$rawToken;
    }
}
