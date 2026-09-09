<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Tenant\ActivateTenantDomainAction;
use App\Application\Tenant\AddCustomDomainAction;
use App\Application\Tenant\ChangePrimaryDomainAction;
use App\Application\Tenant\CheckDomainVerificationAction;
use App\Application\Tenant\DeleteTenantDomainAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddCustomDomainRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DomainManagementController extends Controller
{
    public function index(TenantContext $context): Response
    {
        return Inertia::render('tenant-admin/Domains', [
            'domains' => $context->tenant()->domains()->orderByDesc('is_primary')->get()->map(fn ($domain) => [
                'id' => $domain->id,
                'hostname' => $domain->hostname,
                'type' => $domain->domain_type->value,
                'status' => $domain->status->value,
                'primary' => $domain->is_primary,
                'verificationToken' => $domain->domain_type->value === 'CUSTOM_DOMAIN' ? $domain->verification_token : null,
                'sslStatus' => $domain->ssl_status,
            ]),
        ]);
    }

    public function store(AddCustomDomainRequest $request, TenantContext $context, AddCustomDomainAction $add): RedirectResponse
    {
        $add->execute($context->tenant(), $request->string('hostname')->toString(), $this->admin($request), $request->attributes->get('request_id'));

        return back()->with('success', 'Custom domain added. Complete DNS verification before activation.');
    }

    public function verify(Request $request, string $domain, TenantContext $context, CheckDomainVerificationAction $check): RedirectResponse
    {
        $verified = $check->execute($context->id(), $domain, $this->admin($request), $request->attributes->get('request_id'));

        return back()->with('success', $verified ? 'Ownership verified. Activate the domain when ready.' : 'Verification record was not found yet.');
    }

    public function activate(Request $request, string $domain, TenantContext $context, ActivateTenantDomainAction $activate): RedirectResponse
    {
        $activate->execute($context->id(), $domain, $this->admin($request), $request->attributes->get('request_id'));

        return back()->with('success', 'Domain activated. SSL provisioning remains pending.');
    }

    public function primary(Request $request, string $domain, TenantContext $context, ChangePrimaryDomainAction $change): RedirectResponse
    {
        $change->execute($context->id(), $domain, $this->admin($request), $request->attributes->get('request_id'));

        return back()->with('success', 'Primary domain changed.');
    }

    public function destroy(Request $request, string $domain, TenantContext $context, DeleteTenantDomainAction $delete): RedirectResponse
    {
        $delete->execute($context->id(), $domain, $this->admin($request), $request->attributes->get('request_id'));

        return back()->with('success', 'Custom domain removed.');
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('tenant_admin');

        return $admin;
    }
}
