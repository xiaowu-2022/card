<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthorizeAdminScope
{
    public function __construct(
        private AuthorizationService $authorization,
        private TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next, string $scopeName, string $permission): Response
    {
        $scope = ScopeType::from(strtoupper($scopeName));
        $guardName = $scope === ScopeType::Platform ? 'platform_admin' : 'tenant_admin';
        $guard = Auth::guard($guardName);
        $admin = $guard->user();

        if (! $admin instanceof AdminUser) {
            return redirect()->guest($scope === ScopeType::Platform ? '/platform/login' : '/admin/login');
        }

        if ($admin->status !== AdminUserStatus::Active) {
            $guard->logout();
            abort(403, 'This administrator account is unavailable.');
        }

        $scopeId = $scope === ScopeType::Tenant ? $this->tenantContext->id() : null;
        abort_unless($this->authorization->allows($admin, $scope, $scopeId, $permission), 403);

        Auth::shouldUse($guardName);
        $request->attributes->set('admin_scope_type', $scope->value);
        $request->attributes->set('admin_scope_id', $scopeId);

        return $next($request);
    }
}
