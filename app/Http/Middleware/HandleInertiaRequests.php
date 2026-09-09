<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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
            'requestId' => $request->attributes->get('request_id'),
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'branding' => [
                    'brandName' => $tenant->branding?->brand_name ?? $tenant->name,
                    'primaryColor' => $tenant->branding?->primary_color ?? '#155EEF',
                    'logoUrl' => $tenant->branding?->logo_object_key ? Storage::disk('public')->url($tenant->branding->logo_object_key) : null,
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
