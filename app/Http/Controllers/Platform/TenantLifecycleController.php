<?php

namespace App\Http\Controllers\Platform;

use App\Application\Tenant\ReactivateTenantAction;
use App\Application\Tenant\SuspendTenantAction;
use App\Domain\Admin\Models\AdminUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TenantLifecycleController extends Controller
{
    public function suspend(Request $request, string $tenant, SuspendTenantAction $suspend): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('platform_admin');
        $suspend->execute($tenant, $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Tenant suspended. Records and financial history were not changed.');
    }

    public function reactivate(Request $request, string $tenant, ReactivateTenantAction $reactivate): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = $request->user('platform_admin');
        $reactivate->execute($tenant, $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'Tenant reactivated.');
    }
}
