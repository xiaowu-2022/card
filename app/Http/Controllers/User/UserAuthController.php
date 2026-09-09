<?php

namespace App\Http\Controllers\User;

use App\Application\User\AuthenticateUserAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class UserAuthController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('user/Login');
    }

    public function store(UserLoginRequest $request, TenantContext $context, AuthenticateUserAction $action): RedirectResponse
    {
        $request->ensureIsNotRateLimited($context->id());
        $user = $action->execute($context->id(), $request->string('identifier')->toString(), $request->string('password')->toString(), $request->string('region')->toString() ?: null, $request->attributes->get('request_id'), $request->ip(), $request->userAgent());
        if (! $user) {
            $request->hitRateLimiter($context->id());
            throw ValidationException::withMessages(['identifier' => 'Invalid credentials.']);
        }
        $request->clearRateLimiter($context->id());
        Auth::guard('tenant_user')->login($user);
        $request->session()->regenerate();

        $restricted = $user->status === UserStatus::Suspended || $context->tenant()->status === TenantStatus::Suspended;

        return redirect($restricted ? '/account/restricted' : '/dashboard');
    }

    public function destroy(Request $request, TenantContext $context, AuditLogger $audit): RedirectResponse
    {
        $user = Auth::guard('tenant_user')->user();
        if ($user instanceof User) {
            $audit->record($context->id(), 'USER', $user->id, 'USER_LOGOUT', 'user_authentication', null, null, null, $request->attributes->get('request_id'), $request->ip(), $request->userAgent());
        }
        Auth::guard('tenant_user')->logout();
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
