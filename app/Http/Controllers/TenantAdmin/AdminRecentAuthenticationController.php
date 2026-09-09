<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Domain\Admin\Models\AdminUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminRecentAuthenticationRequest;
use App\Support\Errors\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

final class AdminRecentAuthenticationController extends Controller
{
    public function store(AdminRecentAuthenticationRequest $request): RedirectResponse
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('tenant_admin')->user();
        if (! Hash::check($request->validated('password'), $admin->password)) {
            throw new DomainException('ADMIN_PASSWORD_INVALID', 'The password is incorrect.', 422);
        }
        $request->session()->put("tenant_admin.recent_auth_at.{$admin->id}", now()->getTimestamp());

        return back()->with('success', 'Sensitive document access unlocked for 15 minutes.');
    }
}
