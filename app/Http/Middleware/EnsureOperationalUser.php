<?php

namespace App\Http\Middleware;

use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureOperationalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('tenant_user')->user();
        if ($user instanceof User && $user->status === UserStatus::Suspended) {
            return redirect('/account/restricted');
        }

        return $next($request);
    }
}
