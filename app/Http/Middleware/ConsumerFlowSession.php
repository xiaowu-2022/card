<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Native form proofs use an isolated server session; it never authenticates users. */
final class ConsumerFlowSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('consumer_mode') !== 'mobile') {
            return $next($request);
        }
        $tenant = $request->attributes->get('tenant_id');
        $token = $request->header('X-Consumer-Flow');
        $bootstrap = $request->isMethod('GET') && $request->is('api/mobile/v1/bootstrap');
        $key = is_string($token) && preg_match('/^[A-Za-z0-9]{64}$/D', $token)
            ? 'consumer-flow:'.hash('sha256', $tenant.':'.$token) : null;
        $id = $key ? Cache::get($key) : null;
        if (! is_string($id)) {
            abort_unless($bootstrap, 419);
            $token = Str::random(64);
            $key = 'consumer-flow:'.hash('sha256', $tenant.':'.$token);
            $id = Str::random(40);
            Cache::put($key, $id, now()->addHours(2));
        }

        return Cache::lock($key.':lock', 180)->block(5, function () use ($request, $next, $key, $token): Response {
            $id = Cache::get($key);
            abort_unless(is_string($id), 419);
            $session = new Store('consumer_native_flow', app('session')->driver()->getHandler(), $id);
            $session->start();
            $request->setLaravelSession($session);
            $userId = $request->attributes->get('consumer_user')?->id;
            if ($session->has('consumer_user_id') && $session->get('consumer_user_id') !== $userId) {
                $session->flush();
            }
            if ($userId) {
                $session->put('consumer_user_id', $userId);
            }
            try {
                $response = $next($request);
                $response->headers->set('X-Consumer-Flow', $token);
                return $response;
            } finally {
                $session->save();
                // Rotation by an existing domain controller preserves ownership of this flow.
                Cache::put($key, $session->getId(), now()->addHours(2));
            }
        });
    }
}
