<?php

namespace App\Http\Middleware;

use App\Application\Inbox\InboxQuery;
use App\Application\Media\ImageStorage;
use App\Application\Media\PublicAssets;
use App\Application\Support\SupportUnread;
use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $resolvedTenant = $request->attributes->get('tenant');
        $tenant = $resolvedTenant instanceof Tenant ? $resolvedTenant : null;
        $isPlatform = strtolower($request->getHost()) === strtolower((string) config('tenancy.platform_admin_host'));
        $scope = $isPlatform ? ScopeType::Platform : ScopeType::Tenant;
        $guard = $isPlatform ? 'platform_admin' : 'tenant_admin';
        $admin = Auth::guard($guard)->user();
        $user = $tenant ? Auth::guard('tenant_user')->user() : null;
        $scopeId = $scope === ScopeType::Tenant ? $tenant?->id : null;
        $permissions = $admin instanceof AdminUser
            ? $admin->memberships()
                ->where('scope_type', $scope)
                ->where('scope_id', $scopeId)
                ->where('status', MembershipStatus::Active)
                ->with('role.permissions')
                ->get()
                ->flatMap(fn ($membership) => $membership->role->permissions->pluck('name'))
                ->unique()
                ->values()
            : collect();

        return [
            ...parent::share($request),
            'publicAssets' => fn () => app(PublicAssets::class)->manifest(),
            'unreadSupport' => $user instanceof User && $tenant && $user->tenant_id === $tenant->id
                ? app(SupportUnread::class)->count($tenant->id, $user->id) : 0,
            'unreadMessages' => $user instanceof User && $tenant && $user->tenant_id === $tenant->id
                ? app(InboxQuery::class)->unread($tenant->id, $user->id) : 0,
            'requestId' => $request->attributes->get('request_id'),
            'i18n' => [
                'surface' => $request->attributes->get('locale_surface', 'user'),
                'locale' => $request->attributes->get('client_locale', 'en'),
                'enabledLocales' => $request->attributes->get('client_locales', ['en']),
                'timezone' => $request->attributes->get('client_timezone', 'UTC'),
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'branding' => [
                    'brandName' => $tenant->branding?->brand_name ?? $tenant->name,
                    'primaryColor' => $tenant->branding?->primary_color ?? '#39AD8D',
                    'logoUrl' => $tenant->branding?->logo_object_key ? app(ImageStorage::class)->displayUrl('public', $tenant->branding->logo_object_key, 'brand') : null,
                    'logoSources' => app(ImageStorage::class)->previewSources('public', $tenant->branding?->logo_object_key, 'brand'),
                ],
                'locales' => $tenant->locales->where('enabled', true)->pluck('locale')->values(),
            ] : null,
            'auth' => [
                'admin' => $admin instanceof AdminUser ? [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'scope' => $scope->value,
                    'permissions' => $permissions,
                ] : null,
                'user' => $user instanceof User && $user->tenant_id === $tenant?->id ? [
                    'id' => $user->id,
                    'accountId' => $user->account_id,
                    'displayName' => $user->profile?->display_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'status' => $user->status->value,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
            ],
        ];
    }
}
