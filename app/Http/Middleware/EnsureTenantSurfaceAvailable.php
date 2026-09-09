<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\Enums\TenantSurface;
use App\Domain\Tenant\Enums\TenantSurfaceAccess;
use App\Domain\Tenant\Services\TenantSurfaceAvailability;
use App\Domain\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

final readonly class EnsureTenantSurfaceAvailable
{
    public function __construct(
        private TenantContext $context,
        private TenantSurfaceAvailability $availability,
    ) {}

    public function handle(Request $request, Closure $next, string $surfaceName): Response
    {
        $surface = TenantSurface::tryFrom($surfaceName)
            ?? throw new UnexpectedValueException("Unknown tenant surface [{$surfaceName}].");
        $access = $this->availability->accessFor($this->context->tenant()->status, $surface);

        if ($access === TenantSurfaceAccess::Restricted) {
            if (Auth::guard('tenant_user')->check()) {
                return redirect('/account/restricted');
            }
            abort(423, 'This tenant surface is currently restricted.');
        }

        if ($access === TenantSurfaceAccess::Unavailable) {
            abort(503, 'This tenant surface is currently unavailable.');
        }

        return $next($request);
    }
}
