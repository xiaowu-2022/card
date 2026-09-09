<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $resolvedTenant = $request->attributes->get('tenant');
        $tenant = $resolvedTenant instanceof Tenant ? $resolvedTenant : null;

        return [
            ...parent::share($request),
            'requestId' => $request->attributes->get('request_id'),
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'branding' => [
                    'brandName' => $tenant->branding?->brand_name ?? $tenant->name,
                    'primaryColor' => $tenant->branding?->primary_color ?? '#155EEF',
                    'logoUrl' => null,
                ],
                'locales' => $tenant->locales->where('enabled', true)->pluck('locale')->values(),
            ] : null,
        ];
    }
}
