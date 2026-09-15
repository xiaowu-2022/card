<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final readonly class ThrottleCardTransactionReads
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('tenant_user');
        abort_unless($user instanceof User && $user->tenant_id === $this->context->id(), 401);
        // Unlike Laravel's priority-sorted generic throttle, this route middleware
        // runs AFTER tenant resolution and user scope restoration.
        $key = 'card-transactions:'.$this->context->id().':'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 120)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
