<?php

namespace App\Http\Controllers\User;

use App\Application\User\ChangeUserPasswordAction;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeUserPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class AccountController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('user/Account');
    }

    public function restricted(TenantContext $context): Response|RedirectResponse
    {
        /** @var User|null $user */
        $user = Auth::guard('tenant_user')->user();
        if ($context->tenant()->status === TenantStatus::Active && $user?->status === UserStatus::Active) {
            return redirect('/dashboard');
        }

        return Inertia::render('user/Restricted', ['tenantRestricted' => $context->tenant()->status === TenantStatus::Suspended]);
    }

    public function security(): Response
    {
        return Inertia::render('user/Security');
    }

    public function changePassword(ChangeUserPasswordRequest $request, TenantContext $context, ChangeUserPasswordAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $action->execute($context->id(), $user->id, $request->string('current_password')->toString(), $request->string('password')->toString(), $request->attributes->get('request_id'));
        $request->session()->regenerate();

        return back()->with('success', 'Password changed successfully.');
    }
}
