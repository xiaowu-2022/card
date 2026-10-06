<?php

namespace App\Http\Controllers\Platform;

use App\Application\Support\SupportHours;
use App\Application\Support\SupportQuickReplies;
use App\Application\Support\SupportUserAgents;
use App\Application\Tenant\PlatformListFilters;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class SupportWorkspaceController extends Controller
{
    public function remark(Request $r, string $tenant, string $user)
    {
        app(\App\Application\Support\SupportCustomerRemark::class)->save($tenant, $r->user('platform_admin')->id, $r->all(), null, $user);
        return response()->noContent();
    }

    public function grantUser(Request $r, string $tenant, string $user, SupportUserAgents $agents)
    {
        $agents->grant($tenant, $user, $r->user('platform_admin')->id, $r->all());

        return $r->expectsJson() ? response()->noContent() : back();
    }

    public function hours(Request $r, PlatformListFilters $lists, SupportHours $hours)
    {
        $filters = $lists->validated($r);

        return Inertia::render('platform/SupportHours', ['filters' => $filters, 'companies' => $lists->companies(), 'configuration' => ! empty($filters['company']) ? $hours->configuration($filters['company']) : null]);
    }

    public function saveHours(Request $r, SupportHours $hours)
    {
        $r->validate(['company' => 'required|uuid|exists:tenants,id']);
        $hours->save($r->user('platform_admin')->id, $r->input('company'), $r->all());

        return response()->noContent();
    }

    public function replies(Request $r, PlatformListFilters $lists)
    {
        $filters = $lists->validated($r);
        $rows = DB::table('support_quick_replies as q')->join('tenants as t', 't.id', '=', 'q.tenant_id')->whereNull('q.archived_at')
            ->when($filters['company'] ?? null, fn ($q, $v) => $q->where('q.tenant_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('q.title', 'ilike', '%'.addcslashes($v, '%_').'%')->orWhere('q.body', 'ilike', '%'.addcslashes($v, '%_').'%')))
            ->select('q.*', 't.name as company')->orderBy('q.title')->orderBy('q.id')->paginate(30)->withQueryString();

        return Inertia::render('platform/SupportReplies', ['replies' => $rows, 'filters' => $filters, 'companies' => $lists->companies()]);
    }

    public function saveReply(Request $r, SupportQuickReplies $replies)
    {
        $r->validate(['company' => 'required|uuid|exists:tenants,id']);
        $replies->save($r->user('platform_admin')->id, $r->input('company'), $r->all());

        return response()->noContent();
    }

    public function quick(Request $r, string $tenant, SupportQuickReplies $replies)
    {
        $r->validate(['search' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1']);
        Tenant::findOrFail($tenant);

        return response()->json($replies->visible($tenant, $r->user('platform_admin')->id, $r->string('search'))->paginate(30))->header('Cache-Control', 'private, no-store');
    }
}
