<?php

namespace App\Http\Controllers\Platform;

use App\Application\Tenant\ActivateTenantDomainAction;
use App\Application\Tenant\AssignCompanyDomainsAction;
use App\Application\Tenant\ChangePrimaryDomainAction;
use App\Application\Tenant\DeleteTenantDomainAction;
use App\Application\Tenant\DomainConfigurationQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignCompanyDomainsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DomainManagementController extends Controller
{
    public function index(Tenant $tenant, Request $request): Response|RedirectResponse
    {
        if (! $request->routeIs('platform.company-configuration.domains')) {
            return redirect('/platform/tenants/'.$tenant->id.'/configuration/domains');
        }

        return Inertia::render('platform/Domains', [
            'company' => ['id' => $tenant->id, 'name' => $tenant->name],
            'domains' => app(DomainConfigurationQuery::class)->execute($tenant->id),
        ]);
    }

    public function store(): void
    {
        abort(403);
    }

    public function assign(AssignCompanyDomainsRequest $request, Tenant $tenant, AssignCompanyDomainsAction $assign): RedirectResponse
    {
        $assign->execute($tenant->id, $request->validated('domain_ids'), $request->validated('original_ids'), $this->admin($request), $request->attributes->get('request_id'));

        return back()->with('success', 'Domain assignments saved.');
    }

    public function activate(Request $request, Tenant $tenant, string $domain, ActivateTenantDomainAction $activate): RedirectResponse
    {
        $activate->execute($tenant->id, $domain, $this->admin($request), $request->attributes->get('request_id'));

        return back()->with('success', 'Domain activated.');
    }

    public function primary(Request $request, Tenant $tenant, string $domain, ChangePrimaryDomainAction $change): RedirectResponse
    {
        $change->execute($tenant->id, $domain, $this->admin($request), $request->attributes->get('request_id'));

        return back()->with('success', 'Primary domain changed.');
    }

    public function destroy(Request $request, Tenant $tenant, string $domain, DeleteTenantDomainAction $delete): RedirectResponse
    {
        $delete->execute($tenant->id, $domain, $this->admin($request), $request->attributes->get('request_id'));

        return back()->with('success', 'Custom domain removed.');
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('platform_admin');

        return $admin;
    }
}
