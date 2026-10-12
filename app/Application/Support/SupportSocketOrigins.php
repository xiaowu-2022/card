<?php

namespace App\Application\Support;

use Illuminate\Support\Facades\DB;

final class SupportSocketOrigins
{
    public function allows(?string $origin): bool
    {
        // An Origin names an HTTP origin only: never accept paths, userinfo or wildcards.
        if (! is_string($origin) || strlen($origin) > 300
            || ! preg_match('/\Ahttps?:\/\/([a-z0-9][a-z0-9.-]*)(?::([0-9]{1,5}))?\z/i', $origin, $parts)) {
            return false;
        }
        if (isset($parts[2]) && ((int) $parts[2] < 1 || (int) $parts[2] > 65535)) {
            return false;
        }
        $host = strtolower(rtrim($parts[1], '.'));
        $platform = strtolower(rtrim((string) config('tenancy.platform_admin_host'), '.'));
        if ($host === $platform) {
            return true;
        }

        // Re-read current assignments on each connection. Long-lived Reverb must not
        // retain stale domain ownership or require restarts when an alias is allocated.
        return DB::table('tenant_domains as d')->join('tenants as t', 't.id', '=', 'd.tenant_id')
            ->where('d.hostname', $host)->where('d.status', 'ACTIVE')->where('t.status', 'ACTIVE')->exists();
    }
}
