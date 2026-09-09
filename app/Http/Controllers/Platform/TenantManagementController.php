<?php

namespace App\Http\Controllers\Platform;

use App\Application\Tenant\CreateTenantAction;
use App\Application\Tenant\ListTenantsQuery;
use App\Application\Tenant\TenantDetailQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateTenantRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TenantManagementController extends Controller
{
    public function index(Request $request, ListTenantsQuery $query): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:DRAFT,ACTIVE,SUSPENDED,CLOSED'],
        ]);

        return Inertia::render('platform/Tenants', [
            'tenants' => $query->execute($filters['search'] ?? null, $filters['status'] ?? null),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('platform/TenantCreate', [
            'locales' => config('tenancy.supported_locales'),
            'assets' => config('tenancy.supported_assets'),
            'timezones' => ['UTC', 'Asia/Kuala_Lumpur', 'Asia/Singapore', 'Asia/Hong_Kong', 'Europe/London', 'America/New_York'],
        ]);
    }

    public function store(CreateTenantRequest $request, CreateTenantAction $create): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('platform_admin');
        $created = $create->execute($request->validated(), $actor, $request->attributes->get('request_id'));

        return redirect('/platform/tenants/'.$created->tenant->id)->with('success', 'Tenant created and Owner invitation sent.');
    }

    public function show(string $tenant, TenantDetailQuery $query): Response
    {
        return Inertia::render('platform/TenantDetail', ['tenantRecord' => $query->execute($tenant)]);
    }
}
