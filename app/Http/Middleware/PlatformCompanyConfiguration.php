<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class PlatformCompanyConfiguration
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = $request->route('tenant');
        $tenant = $tenant instanceof Tenant ? $tenant : Tenant::query()->findOrFail($tenant);
        Inertia::share(['configurationReadOnly' => false, 'configurationBase' => '/platform/tenants/'.$tenant->id.'/configuration',
            'configurationCompany' => ['id' => $tenant->id, 'name' => $tenant->name, 'status' => $tenant->status->value]]);

        return $next($request);
    }
}
