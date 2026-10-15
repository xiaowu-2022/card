<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final readonly class ThrottleConsumerBackground
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $limit = match ($feature) {
            'presence' => 60,
            'card-history-diagnostics' => 60,
            default => throw new \InvalidArgumentException('Unknown background limiter.'),
        };
        $user = $request->user('tenant_user');
        abort_unless($user instanceof User && $user->tenant_id === $this->context->id(), 401);
        $key = 'consumer-background:'.$feature.':'.$this->context->id().':'.$user->id;
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $request->attributes->set('failure_rate_limit', ['feature' => $feature, 'limit' => $limit,
                'window_seconds' => 60, 'retry_after' => RateLimiter::availableIn($key)]);
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);
        return $next($request);
    }
}
