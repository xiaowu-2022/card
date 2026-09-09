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

        if (($hasStoredIdentity && ! $user instanceof User)
            || ($user instanceof User && ($user->tenant_id !== $this->tenantContext->id() || $user->status === UserStatus::Disabled))) {
            $guard->logout();
            $request->session()->regenerateToken();
        }

        return $next($request);
    }
}
