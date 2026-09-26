<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireConsumerApiUser
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next, string $access = 'restricted'): Response
    {
        $user = $request->attributes->get('consumer_user');
        abort_unless($user instanceof User && $user->tenant_id === $this->tenant->id() && $user->status !== UserStatus::Disabled, 401);
        if ($access === 'operational') {
            abort_unless($user->status === UserStatus::Active && $this->tenant->tenant()->status === TenantStatus::Active, 403);
        }

        return $next($request);
    }
}
