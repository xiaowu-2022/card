<?php

namespace App\Http\Controllers\Platform;

use App\Application\Tenant\CreateTenantAction;
use App\Application\Tenant\ListTenantsQuery;
use App\Application\Tenant\TenantDetailQuery;
use App\Application\Tenant\UpdateCompanyDepositSettingsAction;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateTenantRequest;
use App\Http\Requests\UpdateCompanyDepositSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TenantManagementController extends Controller
{
    public function index(Request $request, ListTenantsQuery $query, AuthorizationService $authorization): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:DRAFT,ACTIVE,SUSPENDED,CLOSED'],
        ]);

        $allowed = fn (string $permission): bool => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, $permission);
        $financialAccess = ['inflow' => $allowed('wallet_topups.read'), 'outflow' => $allowed('withdrawals.read')];

        return Inertia::render('platform/Tenants', [
            'tenants' => $query->execute($filters['search'] ?? null, $filters['status'] ?? null, $financialAccess),
            'totals' => $query->totals($filters['search'] ?? null, $filters['status'] ?? null, $financialAccess),
            'financialAccess' => $financialAccess,
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

    public function show(string $tenant, TenantDetailQuery $query, Request $request, AuthorizationService $authorization): Response|RedirectResponse
    {
        if ($authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, 'tenant.manage')) {
            Tenant::query()->findOrFail($tenant);

            return redirect('/platform/tenants/'.$tenant.'/configuration/card-products');
        }

        return Inertia::render('platform/TenantDetail', ['tenantRecord' => $query->execute($tenant)]);
    }

    public function updateDeposit(string $tenant, UpdateCompanyDepositSettingsRequest $request, UpdateCompanyDepositSettingsAction $action): RedirectResponse
    {
        $action->execute($tenant, $request->validated('required_security_deposit_amount'), (int) $request->validated('security_deposit_refund_wait_days'), $request->user('platform_admin'), $request->attributes->get('request_id'));

        $businessSettingsPath = '/platform/tenants/'.$tenant.'/configuration/settings/business';
        $returnPath = $request->headers->get('referer') === url($businessSettingsPath)
            ? $businessSettingsPath
            : '/platform/tenants/'.$tenant;

        return redirect($returnPath)->with('success', 'Company security deposit settings saved.');
    }
}
