<?php

namespace App\Http\Controllers\Platform;

use App\Application\Inbox\BroadcastMessages;
use App\Application\Tenant\PlatformListFilters;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class InboxController extends Controller
{
    public function index(Request $request, PlatformListFilters $lists)
    {
        $v = $request->validate(['company' => 'nullable|uuid|exists:tenants,id', 'page' => 'sometimes|integer|min:1|max:100000']);
        $tenant = $v['company'] ?? null;
        $rows = $tenant ? DB::table('inbox_broadcasts as b')->leftJoin('admin_users as a', 'a.id', '=', 'b.actor_id')
            ->where('b.tenant_id', $tenant)->select('b.id', 'b.title', 'b.body', 'b.audience', 'b.recipient_count', 'b.created_at', 'a.name as sender')
            ->selectSub(DB::table('inbox_events as e')->selectRaw('count(*)')->whereColumn('e.broadcast_id', 'b.id')->whereColumn('e.tenant_id', 'b.tenant_id')->whereNotNull('e.delivered_at'), 'delivered')
            ->selectSub(DB::table('inbox_receipts as r')->join('inbox_events as e', 'e.id', '=', 'r.event_id')->selectRaw('count(*)')->whereColumn('e.broadcast_id', 'b.id')->whereColumn('r.tenant_id', 'b.tenant_id')->whereNotNull('r.read_at'), 'read')
            ->orderByDesc('b.created_at')->orderBy('b.id')->paginate(20)->withQueryString()->through(function ($row) {
                $row->created_at = CarbonImmutable::parse($row->created_at)->toIso8601String();

                return $row;
            }) : null;

        return Inertia::render('platform/Notifications', ['companies' => $lists->companies(), 'company' => $tenant, 'batches' => $rows])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function candidates(Request $request, Tenant $tenant)
    {
        $v = $request->validate(['search' => 'required|string|max:100']);
        $pattern = '%'.addcslashes($v['search'], '%_').'%';

        return response()->json(['users' => DB::table('users')->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->where('account_id', 'like', $pattern)->orWhere('email', 'ilike', $pattern))
            ->orderBy('account_id')->limit(30)->get(['id', 'account_id', 'email'])])->header('Cache-Control', 'private, no-store');
    }

    private function data(Request $request, bool $send): array
    {
        return $request->validate([
            'title' => 'required|string|max:100', 'body' => 'required|string|max:5000',
            'audience' => 'required|in:selected,all', 'users' => 'required_if:audience,selected|array|max:500', 'users.*' => 'uuid|distinct',
            ...($send ? ['request_id' => 'required|uuid', 'token' => 'required|string', 'confirmed' => 'accepted'] : []),
        ]);
    }

    public function preview(Request $request, Tenant $tenant, BroadcastMessages $service)
    {
        return response()->json($service->preview($request->user('platform_admin'), $tenant->id, $this->data($request, false)))->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, Tenant $tenant, BroadcastMessages $service)
    {
        $service->send($request->user('platform_admin'), $tenant->id, $this->data($request, true));

        return redirect('/platform/notifications?company='.$tenant->id, 303)->with('success', 'Notification queued for delivery.');
    }
}
