<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Services\AuthorizationService;
use Closure;
use Illuminate\Http\Request;

final class CompanyDirectoryAccess
{
    public function handle(Request $request, Closure $next)
    {
        $actor = $request->user('platform_admin');
        $authorization = app(AuthorizationService::class);
        $allows = fn ($permission) => $actor && $authorization->allows($actor, ScopeType::Platform, null, $permission);
        $permission = 'tenant.read';
        if (! $allows($permission)) {
            if ($allows('tenant.manage')) {
                $permission = 'tenant.manage';
            } elseif ($allows('support.read')) {
                foreach (['support.hours.manage', 'support.replies.manage', 'support.bot.manage'] as $candidate) {
                    if ($allows($candidate)) {
                        $permission = $candidate;
                        break;
                    }
                }
            }
        }

        return app(AuthorizeAdminScope::class)->handle($request, $next, 'platform', $permission);
    }
}
