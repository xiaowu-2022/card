<?php

namespace App\Http\Middleware;

use App\Application\Cleanup\CleanupCompanyFence;
use App\Domain\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class FenceDataCleanup
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->is('platform/tenants/*/data-cleanup*')) {
            return $next($request);
        }
        $tenant = $request->route('tenant');
        $tenant = is_object($tenant) ? $tenant->id : $tenant;
        if (! $tenant) {
            $context = app(TenantContext::class);
            $tenant = $context->hasTenant() ? $context->id() : null;
        }
        if (! is_string($tenant) || ! Str::isUuid($tenant)) {
            return $next($request);
        }

        return app(CleanupCompanyFence::class)->run($tenant, fn () => $next($request));
    }
}
