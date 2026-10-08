<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PlatformEditorResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // Only translate successful, authenticated editor mutations. Validation and
        // authorization responses retain their original status and body.
        if ($request->header('X-Admin-Dialog') === '1') {
            if (! $request->isMethod('GET') && $request->user('platform_admin') && $response->isRedirect()) {
                $location = $response->headers->get('Location', '');
                if (str_contains($location, '/platform/login')) {
                    return response()->json(['message' => 'Please sign in again.'], 401);
                }
                if ($request->session()->has('errors')) {
                    $errors = $request->session()->get('errors')->getBag('default')->messages();

                    return response()->json(['errors' => $errors], 422);
                }

                return response()->json(['saved' => true, 'location' => $request->is('platform/tenants') ? $location : null])->header('Cache-Control', 'private, no-store');
            }

            return $response;
        }
        if (! $request->isMethod('GET') || ! $request->user('platform_admin') || ! $response->isSuccessful()) {
            return $response;
        }
        $path = '/'.$request->path();
        if (in_array($path, ['/platform/support/hours', '/platform/support/replies'], true) && ! $request->filled('company')) {
            return redirect('/platform/tenants?'.http_build_query(['section' => substr($path, strlen('/platform/'))]));
        }
        $list = match (true) {
            $path === '/platform/settings/assets' && $request->filled('company') => '/platform/tenants',
            $path === '/platform/tenants/create' => '/platform/tenants',
            preg_match('#^/platform/tenants/[^/]+/users/[^/]+/(wallet-adjustments|referrer|invitation-code|promotion|manual-commissions)$#', $path) === 1 => '/platform/users',
            preg_match('#^/platform/tenants/[^/]+/configuration/(settings(/(branding|locales|business|kyc|articles|sms|email))?|promotion|paid-promotion|wealth|onboarding|domains|card-products|team)$#', $path) === 1 => '/platform/tenants',
            preg_match('#^/platform/tenants/[^/]+/domains$#', $path) === 1 => '/platform/tenants',
            preg_match('#^/platform/support/(hours|replies|bot)$#', $path) === 1 && $request->filled('company') => '/platform/tenants',
            default => null,
        };

        return $list ? redirect($list.'?'.http_build_query(['editor' => $request->getRequestUri()])) : $response;
    }
}
