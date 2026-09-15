<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\TenantContext;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnforceTenantUserSessionScope
{
    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('tenant_user');
        $hasStoredIdentity = $request->hasSession() && $request->session()->has($guard->getName());
        $user = $guard->user();
        // Pre-upgrade sessions are version zero, never silently promoted after revocation.
        $storedVersion = $request->hasSession() ? $request->session()->get('tenant_user_session_version', 0) : 0;
        $stale = $hasStoredIdentity && $user instanceof User
            && (! is_int($storedVersion) || $storedVersion !== $user->session_version);

        if ($stale || ($hasStoredIdentity && ! $user instanceof User)
            || ($user instanceof User && ($user->tenant_id !== $this->tenantContext->id() || $user->status === UserStatus::Disabled))) {
            $guard->logout();
            $request->session()->forget(['tenant_user_session_version', 'contact_change_binding', 'contact_change_request']);
            $request->session()->regenerateToken();
        }

        return $next($request);
    }
}
