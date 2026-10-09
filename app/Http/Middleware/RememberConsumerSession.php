<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\TenantContext;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Infrastructure\Auth\ConsumerDeviceToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Host-only encrypted cookie restores the consumer guard, never an admin guard. */
final readonly class RememberConsumerSession
{
    public const COOKIE = 'consumer_remember';

    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secure = $request->isSecure() || (bool) config('session.secure');
        $guard = Auth::guard('tenant_user');
        $secret = $request->cookie(self::COOKIE);
        // WebView line switching restores only the company-scoped remember
        // credential, never another host's Laravel/admin session cookie.
        // This marker grants no authentication; it requires a valid credential
        // even if the destination still has an older consumer session.
        $webview = $request->header('X-Consumer-Webview') === '1';
        $token = null;
        if (is_string($secret)) {
            $parts = explode('|', $secret, 2);
            if (count($parts) === 2 && preg_match('/^[1-9][0-9]{0,17}$/D', $parts[0]) && preg_match('/^[A-Za-z0-9]{64}$/D', $parts[1])) {
                $token = ConsumerDeviceToken::query()->where('tenant_id', $this->tenant->id())
                    ->whereKey($parts[0])->where('tokenable_type', User::class)->first();
                $owner = $token ? User::query()->where('tenant_id', $this->tenant->id())->find($token->tokenable_id) : null;
                if (! $token || ! hash_equals($token->token, hash('sha256', $parts[1]))
                    || ! $token->can('browser') || ! $token->expires_at->isFuture()
                    || ! $owner || $owner->status === UserStatus::Disabled || $owner->session_version !== $token->session_version) {
                    $token = null;
                }
            }
            if ($token && (! $guard->check() || ($webview && ($guard->id() !== $owner->id
                || $request->session()->get('tenant_user_session_version', 0) !== $owner->session_version)))) {
                if ($webview) {
                    $request->session()->forget(['contact_change_binding', 'contact_change_request']);
                }
                $guard->login($owner);
                $request->session()->put('tenant_user_session_version', $owner->session_version);
                $request->session()->regenerate();
            } elseif (! $token) {
                $guard->logout();
                $request->session()->forget('tenant_user_session_version');
            }
        }
        if ($webview && ! $token) {
            $guard->logout();
            $request->session()->forget(['tenant_user_session_version', 'contact_change_binding', 'contact_change_request']);
        }

        $response = $next($request);
        $response->headers->set('X-CSRF-Token', $request->session()->token());
        $response->headers->set('Cache-Control', 'private, no-store');
        $user = $guard->user();
        if ($user instanceof User) {
            $user = $user->fresh();
        }
        $loggingOut = $request->isMethod('POST') && $request->is('logout', 'api/v1/logout');
        $authenticated = $user instanceof User && $user->tenant_id === $this->tenant->id()
            && $user->status !== UserStatus::Disabled
            && $request->session()->get('tenant_user_session_version', 0) === $user->session_version;
        if ($loggingOut || ! $authenticated) {
            if ($loggingOut && $token) {
                $token->delete();
            }
            if ($secret !== null) {
                $response->headers->clearCookie(self::COOKIE, '/', null, $secure, true, 'lax');
            }

            return $response;
        }

        $expires = now()->addDays(30);
        if (! $token || $token->tokenable_id !== $user->id) {
            $random = Str::random(64);
            $token = ConsumerDeviceToken::query()->create([
                'tenant_id' => $user->tenant_id, 'tokenable_type' => User::class, 'tokenable_id' => $user->id,
                'name' => 'Browser', 'token' => hash('sha256', $random), 'abilities' => ['browser'],
                'session_version' => $user->session_version, 'expires_at' => $expires,
            ]);
            $secret = $token->id.'|'.$random;
        } else {
            // UPDATE only: a concurrent logout must never be undone by a late response.
            ConsumerDeviceToken::query()->whereKey($token->id)->where('expires_at', '>', now())
                ->where('session_version', $token->session_version)
                ->update(['expires_at' => DB::raw('GREATEST(expires_at, '.DB::getPdo()->quote($expires->toDateTimeString()).')'),
                    'last_used_at' => now(), 'session_version' => $user->session_version]);
        }
        $response->headers->setCookie(cookie(self::COOKIE, $secret, 30 * 24 * 60, '/', null,
            $secure, true, false, 'lax'));

        return $response;
    }
}
