<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Infrastructure\Auth\ConsumerDeviceToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class ConsumerApiContext
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next, string $mode): Response
    {
        abort_unless(in_array($this->tenant->tenant()->status, [TenantStatus::Active, TenantStatus::Suspended], true), 503);
        $request->attributes->set('consumer_mode', $mode);
        $guard = Auth::guard('tenant_user');
        if ($mode === 'mobile') {
            // Native APIs never restore browser/admin cookies or accept a caller-selected tenant.
            $guard->forgetUser();
            $user = null;
            if ($bearer = $request->bearerToken()) {
                $parts = explode('|', $bearer, 2);
                abort_unless(count($parts) === 2 && preg_match('/^[1-9][0-9]{0,17}$/D', $parts[0])
                    && preg_match('/^[A-Za-z0-9]{64}$/D', $parts[1]), 401);
                $token = ConsumerDeviceToken::query()->where('tenant_id', $this->tenant->id())
                    ->whereKey($parts[0])->where('tokenable_type', User::class)->first();
                abort_unless($token && hash_equals($token->token, hash('sha256', $parts[1]))
                    && $token->expires_at->isFuture() && $token->can('consumer'), 401);
                $user = User::query()->where('tenant_id', $this->tenant->id())->whereKey($token->tokenable_id)->first();
                abort_unless($user && $user->status !== UserStatus::Disabled
                    && $user->session_version === $token->session_version, 401);
                $request->attributes->set('consumer_token', $token);
                $guard->setUser($user);
            }
        } else {
            abort_if($request->bearerToken(), 401);
            $user = $guard->user();
        }
        $request->attributes->set('consumer_user', $user);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
