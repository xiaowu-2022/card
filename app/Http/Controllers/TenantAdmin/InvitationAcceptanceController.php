<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Admin\AcceptAdminInvitationAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptAdminInvitationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class InvitationAcceptanceController extends Controller
{
    public function show(string $token, TenantContext $tenantContext, AcceptAdminInvitationAction $accept): Response
    {
        $invitation = $accept->inspect($token, $tenantContext->id());
        $existing = AdminUser::query()->whereRaw('LOWER(email) = ?', [strtolower($invitation->email)])->exists();

        return Inertia::render('tenant-admin/InvitationAccept', [
            'token' => $token,
            'invitation' => ['email' => $invitation->email, 'role' => $invitation->role->name, 'expiresAt' => $invitation->expires_at->toIso8601String()],
            'existingAdmin' => $existing,
        ]);
    }

    public function store(AcceptAdminInvitationRequest $request, string $token, TenantContext $tenantContext, AcceptAdminInvitationAction $accept): RedirectResponse
    {
        $accepted = $accept->execute(
            $token,
            $tenantContext->id(),
            $request->string('name')->toString(),
            $request->string('password')->toString(),
            $request->attributes->get('request_id'),
        );
        Auth::guard('tenant_admin')->login($accepted->admin);
        $request->session()->regenerate();

        return redirect('/admin/onboarding')->with('success', 'Invitation accepted. Finish the Tenant foundation setup.');
    }
}
