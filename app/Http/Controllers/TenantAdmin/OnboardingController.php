<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Tenant\ActivateTenantAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Services\TenantOnboardingStatusService;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class OnboardingController extends Controller
{
    public function show(TenantContext $context, TenantOnboardingStatusService $status): Response
    {
        return Inertia::render('tenant-admin/Onboarding', [
            'tenantRecord' => ['name' => $context->tenant()->name, 'status' => $context->tenant()->status->value],
            'onboarding' => $status->for($context->tenant()),
        ]);
    }

    public function activate(Request $request, TenantContext $context, ActivateTenantAction $activate): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('tenant_admin');
        $activate->execute($context->tenant(), $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Tenant foundation activated. Card service remains unavailable until a future business-readiness phase.');
    }
}
