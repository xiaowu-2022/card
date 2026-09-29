<?php

namespace App\Http\Controllers\Platform;

use App\Application\Support\SendSupportMessageAction;
use App\Application\Support\SupportAccess;
use App\Application\Support\SupportChatQuery;
use App\Application\Support\SupportImageStorage;
use App\Application\Support\SupportProfiles;
use App\Application\Tenant\PlatformListFilters;
use App\Domain\Support\Models\SupportConversation;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendSupportMessageRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class SupportController extends Controller
{
    public function index(Request $r, PlatformListFilters $lists)
    {
        return $this->page($r, $lists);
    }

    public function show(Request $r, Tenant $tenant, string $user, PlatformListFilters $lists, SupportChatQuery $query)
    {
        $r->validate(['before' => ['nullable', 'integer', 'min:0', 'max:2147483647']]);

        return $this->page($r, $lists, $query->platform($tenant->id, $r->user('platform_admin')->id, $user, $r->integer('before')));
    }

    private function page(Request $r, PlatformListFilters $lists, ?array $chat = null)
    {
        $filters = $lists->validated($r, ['status' => ['nullable', 'in:awaiting,replied']]);
        $rows = SupportConversation::query()->join('users as u', fn ($j) => $j->on('u.id', '=', 'support_conversations.user_id')->on('u.tenant_id', '=', 'support_conversations.tenant_id'))
            ->join('tenants as t', 't.id', '=', 'support_conversations.tenant_id')
            ->when($filters['company'] ?? null, fn ($q, $v) => $q->where('support_conversations.tenant_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('last_sender', $v === 'awaiting' ? 'USER' : 'ADMIN'))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('u.account_id', 'like', '%'.addcslashes($v, '%_').'%')->orWhere('u.email', 'ilike', '%'.addcslashes($v, '%_').'%')))
            ->select('support_conversations.*', 'u.account_id', 'u.email', 't.name as company_name')
            ->orderByDesc('support_conversations.updated_at')->orderBy('support_conversations.id')->paginate(30)->withQueryString()
            ->through(fn ($o) => ['id' => $o->id, 'tenantId' => $o->tenant_id, 'userId' => $o->user_id, 'company' => $o->company_name, 'accountId' => $o->account_id,
                'email' => $o->email, 'awaitingReply' => $o->last_sender === 'USER', 'updatedAt' => $o->updated_at->toIso8601String()]);

        return Inertia::render('platform/Support', ['inbox' => $rows, 'chat' => $chat, 'companies' => $lists->companies(), 'filters' => $filters, 'supportName' => $r->user('platform_admin')->support_name])
            ->toResponse($r)->header('Cache-Control', 'private, no-store');
    }

    public function candidates(Request $r, Tenant $tenant)
    {
        $data = $r->validate(['search' => ['required', 'string', 'min:1', 'max:120']]);
        $term = '%'.addcslashes(trim($data['search']), '%_').'%';

        return response()->json(['users' => User::query()->where('tenant_id', $tenant->id)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->where('account_id', 'like', $term)->orWhere('email', 'ilike', $term))
            ->orderBy('account_id')->limit(30)->get(['id', 'account_id', 'email'])])->header('Cache-Control', 'private, no-store');
    }

    public function send(SendSupportMessageRequest $r, Tenant $tenant, string $user, SendSupportMessageAction $action)
    {
        $action->platform($tenant->id, $r->user('platform_admin')->id, $user, $r->validated('request_id'), $r->validated('support_message') ?? '', $r->file('support_image'));

        return redirect('/platform/tenants/'.$tenant->id.'/support/users/'.$user);
    }

    public function image(Request $r, Tenant $tenant, string $message, SupportChatQuery $query, SupportImageStorage $images)
    {
        $image = $query->platformImage($tenant->id, $r->user('platform_admin')->id, $message);

        return $images->response($image['path']);
    }

    public function profile(Request $r, SupportProfiles $profiles)
    {
        $data = $r->validate(['support_name' => ['nullable', 'string', 'max:30']]);
        $profiles->update($r->user('platform_admin')->id, $r->user('platform_admin')->id, $data['support_name'] ?? null, self: true);

        return back()->with('success', 'Support name saved.');
    }

    public function agents(Request $r, SupportProfiles $profiles)
    {
        app(SupportAccess::class)->platform($r->user('platform_admin')->id, 'support.agents.manage');
        $filters = $r->validate(['search' => ['nullable', 'string', 'max:120']]);
        $rows = $profiles->agents()->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.addcslashes($v, '%_').'%')->orWhere('email', 'ilike', '%'.addcslashes($v, '%_').'%')))
            ->orderBy('name')->orderBy('id')->paginate(30)->withQueryString()->through(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'supportName' => $a->support_name]);

        return Inertia::render('platform/SupportAgents', ['agents' => $rows, 'filters' => $filters])->toResponse($r)->header('Cache-Control', 'private, no-store');
    }

    public function updateAgent(Request $r, string $agent, SupportProfiles $profiles)
    {
        $data = $r->validate(['support_name' => ['nullable', 'string', 'max:30']]);
        $profiles->update($r->user('platform_admin')->id, $agent, $data['support_name'] ?? null);

        return back()->with('success', 'Support name saved.');
    }
}
