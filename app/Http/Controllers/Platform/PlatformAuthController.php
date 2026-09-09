<?php

namespace App\Http\Controllers\Platform;

use App\Application\Admin\AuthenticateAdminAction;
use App\Application\Admin\LogoutAdminAction;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformAuthController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        return Auth::guard('platform_admin')->check()
            ? redirect('/platform/tenants')
            : Inertia::render('platform/Login');
    }

    public function store(AdminLoginRequest $request, AuthenticateAdminAction $authenticate): RedirectResponse
    {
        $request->ensureIsNotRateLimited('platform');
        $admin = $authenticate->execute(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            ScopeType::Platform,
            null,
            $request->attributes->get('request_id'),
            $request->ip(),
            $request->userAgent(),
        );

        if (! $admin) {
            $request->hitRateLimiter('platform');
            throw ValidationException::withMessages(['email' => 'The provided credentials or Platform access are invalid.']);
        }

        $request->clearRateLimiter('platform');
        Auth::guard('platform_admin')->login($admin);
        $request->session()->regenerate();

        return redirect('/platform/tenants');
    }

    public function destroy(Request $request, LogoutAdminAction $logout): RedirectResponse
    {
        $admin = Auth::guard('platform_admin')->user();
        if ($admin instanceof AdminUser) {
            $logout->execute($admin, null, $request->attributes->get('request_id'), $request->ip(), $request->userAgent());
        }
        Auth::guard('platform_admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/platform/login');
    }
}
