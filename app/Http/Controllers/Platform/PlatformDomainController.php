<?php

namespace App\Http\Controllers\Platform;

use App\Application\Tenant\ActivateTenantDomainAction;
use App\Application\Tenant\AddCustomDomainAction;
use App\Application\Tenant\CheckDomainVerificationAction;
use App\Application\Tenant\DeleteTenantDomainAction;
use App\Application\Tenant\DomainConfigurationQuery;
use App\Domain\Tenant\Models\TenantDomain;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddCustomDomainRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformDomainController extends Controller
{
    public function index(DomainConfigurationQuery $query): Response
    {
        return Inertia::render('platform/Domains', ['domains' => $query->execute(), 'company' => null, 'companies' => $query->companies()]);
    }

    public function store(AddCustomDomainRequest $request, AddCustomDomainAction $add): RedirectResponse
    {
        $add->execute(null, $request->validated('hostname'), $request->user('platform_admin'), $request->attributes->get('request_id'));

        return back()->with('success', 'Custom domain added. Complete DNS verification before activation.');
    }

    public function verify(Request $request, TenantDomain $domain, CheckDomainVerificationAction $check): RedirectResponse
    {
        $verified = $check->execute($domain->tenant_id, $domain->id, $request->user('platform_admin'), $request->attributes->get('request_id'));

        return back()->with('success', $verified ? 'Ownership verified. Activate the domain when ready.' : 'Verification record was not found yet.');
    }

    public function activate(Request $request, TenantDomain $domain, ActivateTenantDomainAction $activate): RedirectResponse
    {
        $activate->execute($domain->tenant_id, $domain->id, $request->user('platform_admin'), $request->attributes->get('request_id'));

        return back()->with('success', 'Domain activated. SSL provisioning remains pending.');
    }

    public function destroy(Request $request, TenantDomain $domain, DeleteTenantDomainAction $delete): RedirectResponse
    {
        abort_if($domain->tenant_id !== null, 422, 'Remove the company assignment before deleting this domain.');
        $delete->execute(null, $domain->id, $request->user('platform_admin'), $request->attributes->get('request_id'));

        return back()->with('success', 'Custom domain removed.');
    }
}
