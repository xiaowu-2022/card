<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;

// Presentation context only. Existing route authorization remains authoritative.
final class CompanyDrawerScope
{
    public function handle(Request $request, Closure $next)
    {
        $scope = $request->header('X-Admin-Company');
        if ($scope !== null) {
            validator(['company' => $scope], ['company' => 'required|uuid'])->validate();
            $route = $request->route('tenant');
            $routeId = $route instanceof Tenant ? $route->id : $route;
            abort_if($routeId && $routeId !== $scope, 403);
            foreach (['company', 'tenant_id'] as $field) {
                abort_if($request->exists($field) && $request->input($field) !== $scope, 403);
            }
            if ($request->is('platform/support/*', 'platform/settings/assets')) {
                abort_unless($request->input('company') === $scope, 403);
            }
            $tenant = Tenant::query()->findOrFail($scope);
            Inertia::share(['configurationCompany' => ['id' => $tenant->id, 'name' => $tenant->name,
                'slug' => $tenant->slug, 'status' => $tenant->status->value]]);
        }

        return $next($request);
    }
}
