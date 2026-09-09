<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Admin\CancelAdminInvitationAction;
use App\Application\Admin\CreateAdminInvitationAction;
use App\Application\Admin\ResendAdminInvitationAction;
use App\Application\Admin\TenantAdminTeamQuery;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\InviteTenantAdminRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TeamController extends Controller
{
    public function index(TenantContext $context, TenantAdminTeamQuery $query): Response
    {
        return Inertia::render('tenant-admin/Team', ['team' => $query->execute($context->tenant())]);
    }

    public function invite(InviteTenantAdminRequest $request, TenantContext $context, CreateAdminInvitationAction $invite): RedirectResponse
    {
        $role = Role::query()->where('scope_type', ScopeType::Tenant)->where('name', $request->validated('role'))->firstOrFail();
        $invite->execute($context->tenant(), $request->validated('email'), $role, $this->admin($request), $request->attributes->get('request_id'));

        return back()->with('success', 'Tenant administrator invited.');
    }

    public function resend(Request $request, string $invitation, TenantContext $context, ResendAdminInvitationAction $resend): RedirectResponse
    {
        $resend->execute($context->tenant(), $invitation, $this->admin($request), $request->attributes->get('request_id'));

        return back()->with('success', 'Invitation resent. The old link is invalid.');
    }

    public function cancel(Request $request, string $invitation, TenantContext $context, CancelAdminInvitationAction $cancel): RedirectResponse
    {
        $cancel->execute($context->tenant(), $invitation, $this->admin($request), $request->attributes->get('request_id'));

        return back()->with('success', 'Pending invitation cancelled.');
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('tenant_admin');

        return $admin;
    }
}
