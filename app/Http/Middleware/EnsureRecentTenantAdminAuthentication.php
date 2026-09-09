<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRecentTenantAdminAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('tenant_admin')->user();
        $timestamp = $admin instanceof AdminUser ? $request->session()->get("tenant_admin.recent_auth_at.{$admin->id}") : null;
        $valid = is_int($timestamp) && $timestamp >= now()->subSeconds((int) config('kyc.admin_recent_auth_ttl_seconds'))->getTimestamp();
        abort_unless($valid, 403, 'Confirm your password before viewing identity documents.');

        return $next($request);
    }
}
