<?php

namespace App\Http\Controllers\User;

use App\Application\Inbox\InboxQuery;
use App\Application\Support\SupportUnread;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class InboxController extends Controller
{
    public function index(Request $request, TenantContext $tenant, InboxQuery $query)
    {
        $v = $request->validate(['filter' => 'sometimes|in:all,unread,business,platform', 'page' => 'sometimes|integer|min:1|max:100000']);
        $filter = $v['filter'] ?? 'all';

        return Inertia::render('user/Messages', ['messages' => $query->listing($tenant->id(), $request->user('tenant_user')->id, $filter), 'filter' => $filter])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, TenantContext $tenant, InboxQuery $query, string $message)
    {
        return Inertia::render('user/Message', ['message' => $query->detail($tenant->id(), $request->user('tenant_user')->id, $message)])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function unread(Request $request, TenantContext $tenant, InboxQuery $query)
    {
        return response()->json(['count' => $query->unread($tenant->id(), $request->user('tenant_user')->id),
            'supportCount' => app(SupportUnread::class)->count($tenant->id(), $request->user('tenant_user')->id)])->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, TenantContext $tenant, InboxQuery $query, string $message)
    {
        $query->read($tenant->id(), $request->user('tenant_user')->id, $message);

        return back(303);
    }

    public function readAll(Request $request, TenantContext $tenant, InboxQuery $query)
    {
        $query->read($tenant->id(), $request->user('tenant_user')->id, null);

        return back(303);
    }
}
