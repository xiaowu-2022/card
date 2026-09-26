<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** Reuse scoped consumer controllers/DTOs; uni-app renders the UI, never embedded HTML. */
final class ConsumerPageResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->attributes->get('tenant');
        $user = $request->attributes->get('consumer_user');
        $request->headers->set('X-Inertia', 'true');
        foreach (['X-Inertia-Version', 'X-Inertia-Partial-Data', 'X-Inertia-Partial-Except', 'X-Inertia-Partial-Component'] as $header) {
            $request->headers->remove($header);
        }
        Inertia::flushShared();
        Inertia::share([
            'flash' => ['success' => fn () => $request->session()->pull('success')],
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'branding' => [
                'brandName' => $tenant->branding?->brand_name ?? $tenant->name,
                'primaryColor' => $tenant->branding?->primary_color ?? '#39AD8D',
                'logoUrl' => $tenant->branding?->logo_object_key ? Storage::disk('public')->url($tenant->branding->logo_object_key) : null,
            ]],
            'auth' => ['user' => $user ? ['id' => $user->id, 'accountId' => $user->account_id,
                'displayName' => $user->profile?->display_name, 'email' => $user->email,
                'phone' => $user->phone, 'status' => $user->status->value] : null],
            'i18n' => ['locale' => $request->attributes->get('client_locale'),
                'enabledLocales' => $request->attributes->get('client_locales'),
                'timezone' => $request->attributes->get('client_timezone')],
        ]);
        // Existing back() responses return to the current uni-app screen. Only a local
        // path is accepted; it is not used for authorization or business lookup.
        $from = $request->header('X-Consumer-Page', '/');
        if (! is_string($from) || ! preg_match('~^/(?!/)[a-zA-Z0-9/_?=&%.+\-]*$~D', $from)) {
            $from = '/';
        }
        $request->headers->set('referer', $request->getSchemeAndHttpHost().$from);
        $response = $next($request);
        if ($response->isRedirection()) {
            $errors = $request->session()->pull('errors');
            if ($errors instanceof \Illuminate\Support\ViewErrorBag && $errors->any()) {
                return response()->json(['errors' => $errors->getBag('default')->messages()], 422)
                    ->header('Cache-Control', 'private, no-store');
            }
            $location = $response->headers->get('Location');
            $host = parse_url($location, PHP_URL_HOST);
            abort_if($host && strcasecmp($host, $request->getHost()) !== 0, 502);
            $path = parse_url($location, PHP_URL_PATH) ?: '/';
            $query = parse_url($location, PHP_URL_QUERY);
            $response = response()->json(['redirect' => $path.($query ? '?'.$query : ''),
                'success' => $request->session()->pull('success'),
                'csrfToken' => $request->attributes->get('consumer_mode') === 'web' ? $request->session()->token() : null]);
        } elseif ($response instanceof JsonResponse && $response->headers->has('X-Inertia')) {
            $page = $response->getData(true);
            $response = response()->json(['component' => $page['component'], 'props' => $page['props']]);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
