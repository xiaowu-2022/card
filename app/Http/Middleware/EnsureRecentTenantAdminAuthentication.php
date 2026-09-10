<?php

namespace App\Http\Middleware;

use App\Application\Admin\TenantAdminRecentAuthentication;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureRecentTenantAdminAuthentication
{
    public function __construct(private TenantAdminRecentAuthentication $recent, private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('tenant_admin')->user();
        $valid = $admin instanceof AdminUser && $this->recent->valid($request->session(), $admin, $this->tenantContext->id());
        abort_unless($valid, 403, 'Confirm your password before accessing sensitive information.');

        return $next($request);
    }
}
