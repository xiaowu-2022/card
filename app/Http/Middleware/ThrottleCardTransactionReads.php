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
        $operation = $request->isMethod('GET') ? 'read' : 'sync';
        $limit = $operation === 'read' ? (int) config('business-throttle.read_per_minute') : 240;
        $key = 'card-transactions:'.$operation.':'.$this->context->id().':'.$user->id;
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $request->attributes->set('failure_rate_limit', ['feature' => 'card-transactions:'.$operation, 'limit' => $limit, 'window_seconds' => 60, 'retry_after' => RateLimiter::availableIn($key)]);
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
