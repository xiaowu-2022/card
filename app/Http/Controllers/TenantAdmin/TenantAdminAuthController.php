<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Admin\AuthenticateAdminAction;
use App\Application\Admin\LogoutAdminAction;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class TenantAdminAuthController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        return Auth::guard('tenant_admin')->check()
            ? redirect('/admin')
            : Inertia::render('tenant-admin/Login');
    }

    public function store(AdminLoginRequest $request, TenantContext $tenantContext, AuthenticateAdminAction $authenticate, AuthorizationService $authorization): RedirectResponse
    {
        $surfaceKey = 'tenant:'.$tenantContext->id();
        $request->ensureIsNotRateLimited($surfaceKey);
        $admin = $authenticate->execute(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            ScopeType::Tenant,
            $tenantContext->id(),
            $request->attributes->get('request_id'),
            $request->ip(),
            $request->userAgent(),
        );

        if (! $admin) {
            $request->hitRateLimiter($surfaceKey);
            throw ValidationException::withMessages(['email' => 'The provided credentials or Tenant access are invalid.']);
        }

        $request->clearRateLimiter($surfaceKey);
        Auth::guard('tenant_admin')->login($admin);
        $request->session()->regenerate();

        return redirect($authorization->allows($admin, ScopeType::Tenant, $tenantContext->id(), 'tenant.activate')
            ? '/admin/onboarding'
            : '/admin/demo');
    }

    public function destroy(Request $request, TenantContext $tenantContext, LogoutAdminAction $logout): RedirectResponse
    {
        $admin = Auth::guard('tenant_admin')->user();
        if ($admin instanceof AdminUser) {
            $logout->execute($admin, $tenantContext->id(), $request->attributes->get('request_id'), $request->ip(), $request->userAgent());
        }
        Auth::guard('tenant_admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/admin/login');
    }
}
