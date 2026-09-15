<?php

namespace App\Http\Controllers\Platform\CompanyConfiguration;

use App\Application\Tenant\ActivateTenantAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Services\TenantOnboardingStatusService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OnboardingController extends Controller
{
    public function show(Tenant $tenant, TenantOnboardingStatusService $status): Response
    {
        return Inertia::render('tenant-admin/Onboarding', [
            'tenantRecord' => ['name' => $tenant->name, 'status' => $tenant->status->value],
            'onboarding' => $status->for($tenant),
        ]);
    }

    public function activate(Tenant $tenant, Request $request, ActivateTenantAction $activate): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('platform_admin');
        $activate->execute($tenant, $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Tenant foundation activated. Card service remains unavailable until a future business-readiness phase.');
    }
}
