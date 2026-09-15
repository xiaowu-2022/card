<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveAdminLocale
{
    public const LOCALES = ['zh-CN', 'en'];

    public static function cookieName(Request $request): string
    {
        $tenant = $request->attributes->get('tenant');

        return $tenant instanceof Tenant ? 'admin_locale_'.$tenant->id : 'platform_admin_locale';
    }

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->cookie(self::cookieName($request));
        $tenant = $request->attributes->get('tenant');
        $request->attributes->set('client_locale', in_array($locale, self::LOCALES, true) ? $locale : 'zh-CN');
        $request->attributes->set('client_locales', self::LOCALES);
        $request->attributes->set('client_timezone', $tenant instanceof Tenant ? $tenant->timezone : config('app.timezone'));
        $request->attributes->set('locale_surface', $tenant instanceof Tenant ? 'tenant-admin' : 'platform');

        // Keep backend business/error keys stable; translate their safe presentation in the Admin namespace.
        return $next($request);
    }
}
