<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserPreference;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResolveUserLocale
{
    public function __construct(private TenantContext $context) {}

    public static function cookieName(string $tenantId): string
    {
        return 'user_locale_'.$tenantId;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->tenant();
        $locales = $tenant->locales()->where('enabled', true)
            ->whereIn('locale', config('tenancy.supported_locales'))->get();
        $enabled = $locales->pluck('locale')->all();
        $default = $locales->firstWhere('is_default', true)?->locale ?? $enabled[0] ?? 'en';
        $user = Auth::guard('tenant_user')->user();
        $preference = $user instanceof User && $user->tenant_id === $tenant->id
            ? UserPreference::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->value('locale')
            : null;
        $cookie = $request->cookie(self::cookieName($tenant->id));
        $browser = null;
        foreach ($request->getLanguages() as $language) {
            $tag = strtolower(str_replace('_', '-', $language));
            $browser = collect($enabled)->first(fn (string $item): bool => strtolower($item) === $tag)
                ?? collect($enabled)->first(fn (string $item): bool => explode('-', strtolower($item))[0] === explode('-', $tag)[0]);
            if ($browser !== null) {
                break;
            }
        }
        $locale = collect([$preference, $cookie, $browser, $default])
            ->first(fn ($candidate): bool => is_string($candidate) && in_array($candidate, $enabled, true)) ?? $default;
        $request->attributes->set('client_locale', $locale);
        $request->attributes->set('client_locales', $enabled);
        $request->attributes->set('client_timezone', $tenant->timezone ?: 'UTC');
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            return $next($request);
        } finally {
            app()->setLocale($previous);
        }
    }
}
