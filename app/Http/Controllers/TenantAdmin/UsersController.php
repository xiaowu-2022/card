<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\User\ReactivateUserAction;
use App\Application\User\SuspendUserAction;
use App\Application\User\TenantUserDirectoryQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class UsersController extends Controller
{
    public function index(TenantContext $context, TenantUserDirectoryQuery $query): Response
    {
        return Inertia::render('tenant-admin/Users', ['users' => $query->paginate($context->id())]);
    }

    public function show(string $user, TenantContext $context, TenantUserDirectoryQuery $query): Response
    {
        return Inertia::render('tenant-admin/UserDetail', $query->detail($context->id(), $user));
    }

    public function suspend(string $user, Request $request, TenantContext $context, SuspendUserAction $action): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = Auth::guard('tenant_admin')->user();
        $action->execute($context->id(), $user, $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'User suspended.');
    }

    public function reactivate(string $user, Request $request, TenantContext $context, ReactivateUserAction $action): RedirectResponse
    {
        /** @var AdminUser $actor */
        $actor = Auth::guard('tenant_admin')->user();
        $action->execute($context->id(), $user, $actor, $request->attributes->get('request_id'));

        return back()->with('success', 'User reactivated.');
    }
}
