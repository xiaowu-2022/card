<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/** Runs after route identity restoration, unlike priority-sorted generic throttle. */
final readonly class ThrottleBusinessRequests
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $allowance = '60', string $minutes = '1', string $unusedPrefix = ''): Response
    {
        $route = $request->route();
        $tenant = $this->context->hasTenant() ? $this->context->id() : 'platform';
        $bound = $route?->parameter('tenant');
        $bound = $bound instanceof \Illuminate\Database\Eloquent\Model ? $bound->getKey() : $bound;
        if ($tenant === 'platform' && is_string($bound) && \Illuminate\Support\Str::isUuid($bound)) $tenant = $bound;
        $guard = match ($request->attributes->get('admin_scope_type')) {
            'PLATFORM' => 'platform_admin', 'TENANT' => 'tenant_admin', default => 'tenant_user',
        };
        $actor = $request->attributes->get('consumer_user') ?? $request->user($guard);
        $identity = $actor ? $guard.':'.$actor->getAuthIdentifier() : 'guest:'.strtolower($request->getHost()).':'.$request->ip();
        // Controller action avoids route IDs, query parameters or aliases creating fresh budgets.
        $feature = $route?->getActionName() ?? $request->method().':unknown';
        if ($feature === 'Closure') $feature = $request->method().':'.$route->uri();
        $limit = match ($allowance) {
            'wallet-topups' => (int) config('payment.topup_rate_limit_per_minute'),
            'security-deposit-funding' => (int) config('security-deposit.funding_rate_limit_per_minute'),
            'withdrawals' => (int) config('withdrawal.creation_rate_limit_per_minute'),
            'cards' => 20,
            default => max((int) $allowance, (int) config($request->isMethod('GET') ? 'business-throttle.read_per_minute' : 'business-throttle.write_per_minute')),
        };
        if ($allowance === 'cards' && str_contains($feature, 'CardManagementController')) {
            $action = $request->input('action');
            $action = in_array($action, ['history', 'refresh', 'sync', 'confirm', 'reveal', 'otp', 'quote', 'load', 'return', 'freeze', 'unfreeze', 'cancel', 'holder', 'holder_details'], true) ? $action : 'other';
            $feature .= ':'.$action;
            if (in_array($action, ['history', 'holder_details'], true)) $limit = (int) config('business-throttle.read_per_minute');
        }
        // Password-protected card operations keep the previous shared 20/min ceiling.
        if ($request->exists('current_password')) {
            $passwordLimit = is_numeric($allowance) ? max(1, (int) $allowance) : max(1, min(20, $limit));
            $this->consume('password:'.(is_numeric($allowance) ? $feature : $allowance), $tenant, $identity, $passwordLimit, 60, $request);
        }
        $this->consume($feature, $tenant, $identity, max(1, $limit), max(1, (int) $minutes) * 60, $request);
        return $next($request);
    }

    private function consume(string $feature, string $tenant, string $identity, int $limit, int $seconds, Request $request): void
    {
        $key = 'business:'.hash('sha256', implode('|', [$feature, $tenant, $identity, $limit, $seconds]));
        $counted = $request->attributes->get('business_throttle_counted', []);
        if (isset($counted[$key])) return;
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retry = RateLimiter::availableIn($key);
            $request->attributes->set('failure_rate_limit', ['feature' => $feature, 'limit' => $limit, 'window_seconds' => $seconds, 'retry_after' => $retry]);
            throw new TooManyRequestsHttpException($retry);
        }
        RateLimiter::hit($key, $seconds);
        $counted[$key] = true;
        $request->attributes->set('business_throttle_counted', $counted);
    }
}
