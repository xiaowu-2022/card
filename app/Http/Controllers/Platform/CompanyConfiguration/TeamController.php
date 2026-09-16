<?php

namespace App\Http\Controllers\Platform\CompanyConfiguration;

use App\Application\Admin\CancelAdminInvitationAction;
use App\Application\Admin\CreateAdminInvitationAction;
use App\Application\Admin\CreateTenantAdminAction;
use App\Application\Admin\ResendAdminInvitationAction;
use App\Application\Admin\TenantAdminTeamQuery;
use App\Application\Admin\UpdateTenantAdminMembershipAction;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateTenantAdminRequest;
use App\Http\Requests\InviteTenantAdminRequest;
use App\Http\Requests\UpdateTenantAdminMembershipRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TeamController extends Controller
{
    public function index(Tenant $tenant, TenantAdminTeamQuery $query): Response
    {
        return Inertia::render('tenant-admin/Team', ['team' => $query->execute($tenant)]);
    }

    public function store(Tenant $tenant, CreateTenantAdminRequest $request, CreateTenantAdminAction $create): RedirectResponse
    {
        $data = $request->validated();
        $create->execute($tenant, $this->admin($request), $data['name'], $data['email'], $data['password'], $data['role'], $request->attributes->get('request_id'));

        return back()->with('success', 'Administrator created. They can sign in to this company with the configured account and password.');
    }

    public function invite(Tenant $tenant, InviteTenantAdminRequest $request, CreateAdminInvitationAction $invite): RedirectResponse
    {
        $role = Role::query()->where('scope_type', ScopeType::Tenant)->where('name', $request->validated('role'))->firstOrFail();
        $invite->execute($tenant, $request->validated('email'), $role, $this->admin($request), $request->attributes->get('request_id'));

        return back()->with('success', 'Tenant administrator invited.');
    }

    public function update(Tenant $tenant, string $membership, UpdateTenantAdminMembershipRequest $request, UpdateTenantAdminMembershipAction $update): RedirectResponse
    {
        $data = $request->validated();
        $update->execute($tenant, $membership, $this->admin($request), $data['role'], $data['status'], $request->attributes->get('request_id'));

        return back()->with('success', 'Company administrator updated.');
    }

    public function resend(Tenant $tenant, Request $request, string $invitation, ResendAdminInvitationAction $resend): RedirectResponse
    {
        $resend->execute($tenant, $invitation, $this->admin($request), $request->attributes->get('request_id'));

        return back()->with('success', 'Invitation resent. The old link is invalid.');
    }

    public function cancel(Tenant $tenant, Request $request, string $invitation, CancelAdminInvitationAction $cancel): RedirectResponse
    {
        $cancel->execute($tenant, $invitation, $this->admin($request), $request->attributes->get('request_id'));

        return back()->with('success', 'Pending invitation cancelled.');
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user('platform_admin');

        return $admin;
    }
}
