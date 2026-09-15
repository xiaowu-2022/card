<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class CompanyConfigurationReadOnly
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('admin') || preg_match('#^admin/(settings|card-products|promotion|team|onboarding)(/|$)#', $request->path())) {
            abort_unless($request->isMethod('GET') || $request->isMethod('HEAD'), 403, 'Company configuration is managed by SaaS.');
            Inertia::share(['configurationReadOnly' => true, 'configurationBase' => null, 'configurationCompany' => null]);
        }

        return $next($request);
    }
}
