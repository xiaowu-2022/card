<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final readonly class ThrottlePromotionPurchase
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $operation): Response
    {
        $limit = match ($operation) {
            'quote' => 20,
            'confirm' => 10,
        };
        $user = $request->user('tenant_user');
        abort_unless($user instanceof User && $user->tenant_id === $this->context->id(), 401);
        // Run after consumer identity restoration, including native Bearer auth.
        // Do not share Laravel's unnamed IP/default-guard bucket with polling.
        // All order IDs, company aliases and web/native entry points share the
        // same per-user operation allowance; failed password attempts count too.
        $key = 'promotion-purchase:'.$operation.':'.$this->context->id().':'.$user->id;
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
