<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\TenantContext;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureAuthenticatedTenantUser
{
    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('tenant_user')->user();
        if (! $user instanceof User || $user->tenant_id !== $this->tenantContext->id() || $user->status === UserStatus::Disabled) {
            Auth::guard('tenant_user')->logout();

            return redirect('/login');
        }

        return $next($request);
    }
}
