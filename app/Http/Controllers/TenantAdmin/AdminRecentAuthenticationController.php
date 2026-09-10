<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Admin\TenantAdminRecentAuthentication;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminRecentAuthenticationRequest;
use App\Support\Errors\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

final class AdminRecentAuthenticationController extends Controller
{
    public function store(AdminRecentAuthenticationRequest $request, TenantContext $tenantContext, TenantAdminRecentAuthentication $recent): RedirectResponse
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('tenant_admin')->user();
        if (! Hash::check($request->validated('password'), $admin->password)) {
            throw new DomainException('ADMIN_PASSWORD_INVALID', 'The password is incorrect.', 422);
        }
        $recent->mark($request->session(), $admin, $tenantContext->id());

        return back()->with('success', 'Sensitive access unlocked for 15 minutes.');
    }
}
