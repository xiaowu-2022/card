<?php

namespace App\Http\Controllers\Api;

use App\Application\Support\ConsumerPresence;
use App\Application\Support\SendSupportMessageAction;
use App\Application\Support\SupportBot;
use App\Application\Support\SupportChatQuery;
use App\Application\Support\SupportImageStorage;
use App\Application\Support\SupportMessageChanges;
use App\Application\Support\SupportUserAgents;
use App\Domain\Support\Models\SupportConversation;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendSupportMessageRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SupportWorkspaceController extends Controller
{
    private function identity(Request $r, TenantContext $context): array
    {
        $actor = $r->attributes->get('consumer_user')->id;
        app(SupportUserAgents::class)->authorize($context->id(), $actor);

        return [$context->id(), $actor];
    }

    public function index(Request $r, TenantContext $context)
    {
        [$tenant,$actor] = $this->identity($r, $context);
        $data = $r->validate(['search' => 'nullable|string|max:120', 'status' => 'nullable|in:awaiting,ALL,BOT,WAITING,HUMAN', 'page' => 'nullable|integer|min:1']);
        $status = $data['status'] ?? 'ALL';
        $awaiting = fn ($q) => $q->where('mode', 'WAITING')->orWhere(fn ($q) => $q->where('mode', 'HUMAN')->where('last_sender', 'USER'));
        // Company-shared inbox: never scope conversations to a previous replying agent.
        $base = SupportConversation::query()->where('support_conversations.tenant_id', $tenant)->where('support_conversations.user_id', '!=', $actor);
        $awaitingCount = (clone $base)->where($awaiting)->count();
        $pendingMessageCount = (clone $base)->where($awaiting)
            ->join('support_messages as pending', fn ($j) => $j->on('pending.conversation_id', '=', 'support_conversations.id')->on('pending.tenant_id', '=', 'support_conversations.tenant_id'))
            ->whereNotNull('pending.sender_user_id')
            ->whereRaw('pending.sequence > COALESCE((SELECT MAX(reply.sequence) FROM support_messages AS reply WHERE reply.tenant_id = pending.tenant_id AND reply.conversation_id = pending.conversation_id AND (reply.sender_admin_id IS NOT NULL OR reply.sender_support_user_id IS NOT NULL)), 0)')
            ->count();
        $rows = $base
            ->join('users as u', fn ($j) => $j->on('u.id', '=', 'support_conversations.user_id')->on('u.tenant_id', '=', 'support_conversations.tenant_id'))
            ->leftJoin('user_profiles as p', fn ($j) => $j->on('p.user_id', '=', 'u.id')->on('p.tenant_id', '=', 'u.tenant_id'))
            ->when($status === 'awaiting', fn ($q) => $q->where($awaiting))
            ->when(in_array($status, ['BOT', 'WAITING', 'HUMAN']), fn ($q) => $q->where('mode', $status))
            ->when($data['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('u.account_id', 'like', '%'.addcslashes($v, '%_').'%')->orWhere('u.email', 'ilike', '%'.addcslashes($v, '%_').'%')->orWhere('p.display_name', 'ilike', '%'.addcslashes($v, '%_').'%')->orWhere('u.support_remark', 'ilike', '%'.addcslashes($v, '%_').'%')))
            ->select('support_conversations.*', 'u.account_id', 'p.display_name', 'u.support_remark')
            ->selectRaw('(SELECT COUNT(*) FROM support_messages m WHERE m.tenant_id = support_conversations.tenant_id AND m.conversation_id = support_conversations.id AND m.sender_user_id IS NOT NULL AND m.sequence > COALESCE((SELECT r.through_sequence FROM support_agent_reads r WHERE r.tenant_id = m.tenant_id AND r.conversation_id = m.conversation_id AND r.user_id = ?), 0)) AS unread_count', [$actor])
            ->selectRaw('(SELECT m.created_at FROM support_messages m WHERE m.tenant_id = support_conversations.tenant_id AND m.conversation_id = support_conversations.id ORDER BY m.sequence DESC LIMIT 1) AS last_message_at')
            ->orderByDesc('unread_count')
            ->orderByRaw("CASE WHEN mode = 'WAITING' OR (mode = 'HUMAN' AND last_sender = 'USER') THEN 0 ELSE 1 END")
            ->orderByDesc('support_conversations.updated_at')->orderBy('support_conversations.id')->paginate(30);
        $online = app(ConsumerPresence::class)->onlineUsers($tenant, $rows->getCollection()->pluck('user_id')->all());
        $rows->through(fn ($row) => ['id' => $row->id, 'accountId' => $row->account_id, 'name' => $row->support_remark ?? $row->display_name, 'online' => in_array($row->user_id, $online, true), 'mode' => $row->mode, 'updatedAt' => $row->updated_at->toIso8601String(), 'unreadCount' => (int) $row->unread_count, 'lastMessageAt' => $row->last_message_at ? \Carbon\Carbon::parse($row->last_message_at)->toIso8601String() : null]);

        return response()->json(['inbox' => $rows, 'awaitingCount' => $awaitingCount, 'pendingMessageCount' => $pendingMessageCount, 'profile' => app(SupportUserAgents::class)->profile($tenant, $actor)])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, TenantContext $context, string $conversation, SupportChatQuery $query)
    {
        [$tenant,$actor] = $this->identity($r, $context);
        $r->validate(['before' => 'nullable|integer|min:0|max:2147483647']);
        $row = app(SupportUserAgents::class)->conversation($tenant, $actor, $conversation);
        $chat = $query->thread($tenant, $row, $r->integer('before'), true, $actor);
        $customer = User::where('tenant_id', $tenant)->with('profile')->findOrFail($row->user_id);
        $chat['customerEmail'] = $customer->email;
        $chat['customerName'] = $customer->support_remark ?? ($customer->profile?->display_name ?: $customer->account_id);
        $chat['customerOnline'] = app(ConsumerPresence::class)->online($tenant, $row->user_id);
        foreach ($chat['messages'] as &$message) {
            if ($message['imageUrl'] && str_starts_with($message['imageUrl'], '/admin/')) {
                $message['imageUrl'] = '/support-workspace/images/'.$message['id'];
            }
        }

        return response()->json($chat)->header('Cache-Control', 'private, no-store');
    }

    public function remark(Request $r, TenantContext $context, string $conversation)
    {
        [$tenant, $actor] = $this->identity($r, $context);
        app(\App\Application\Support\SupportCustomerRemark::class)->save($tenant, $actor, $r->all(), $conversation);
        return response()->noContent();
    }

    public function customer(Request $r, TenantContext $context, string $conversation)
    {
        [$tenant, $actor] = $this->identity($r, $context);
        return response()->json(app(\App\Application\Support\SupportCustomerProfile::class)->get($tenant, $actor, $conversation))->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $r, TenantContext $context, string $conversation)
    {
        [$tenant, $actor] = $this->identity($r, $context);
        $data = $r->validate(['through' => 'required|integer|min:0']);
        DB::transaction(function () use ($tenant, $actor, $conversation, $data) {
            \App\Domain\Tenant\Models\Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $row = app(SupportUserAgents::class)->conversation($tenant, $actor, $conversation);
            abort_if($data['through'] > $row->last_sequence, 422);
            DB::statement('INSERT INTO support_agent_reads (tenant_id, conversation_id, user_id, through_sequence) VALUES (?, ?, ?, ?) ON CONFLICT (tenant_id, conversation_id, user_id) DO UPDATE SET through_sequence = GREATEST(support_agent_reads.through_sequence, EXCLUDED.through_sequence)', [$tenant, $conversation, $actor, $data['through']]);
        });

        return response()->noContent();
    }

    public function send(SendSupportMessageRequest $r, TenantContext $context, string $conversation, SendSupportMessageAction $action)
    {
        [$tenant,$actor] = $this->identity($r, $context);
        app(SupportUserAgents::class)->conversation($tenant, $actor, $conversation);
        $action->agent($tenant, $actor, $conversation, $r->validated('request_id'), $r->validated('support_message') ?? '', $r->resolvedImages($tenant, $actor, 'support', ['support_image'])['support_image'] ?? null);

        return response()->noContent();
    }

    public function changeMessage(Request $r, TenantContext $context, string $conversation, string $message, SupportMessageChanges $changes)
    {
        [$tenant, $actor] = $this->identity($r, $context);
        $changes->change($tenant, $actor, $conversation, $message, $r->all());

        return response()->noContent();
    }

    public function finish(Request $r, TenantContext $context, string $conversation, SupportBot $bot)
    {
        [$tenant,$actor] = $this->identity($r, $context);
        $data = $r->validate(['request_id' => 'required|uuid', 'revision' => 'required|integer|min:1']);
        $bot->finishAgent($tenant, $actor, $conversation, $data['request_id'], $data['revision']);

        return response()->noContent();
    }

    public function image(Request $r, TenantContext $context, string $message, SupportImageStorage $images)
    {
        [$tenant,$actor] = $this->identity($r, $context);
        $row = SupportMessage::where('tenant_id', $tenant)->findOrFail($message);
        app(SupportUserAgents::class)->conversation($tenant, $actor, $row->conversation_id);
        abort_unless($row->image_object_key, 404);
        abort_if(app(SupportMessageChanges::class)->latest($tenant, $message)?->operation === 'DELETE', 404);

        return $images->response($row->image_object_key);
    }

    public function profile(Request $r, TenantContext $context, SupportUserAgents $agents)
    {
        [$tenant,$actor] = $this->identity($r, $context);
        $agents->nickname($tenant, $actor, $r->all());

        return response()->noContent();
    }

    public function replies(Request $r, TenantContext $context, SupportUserAgents $agents)
    {
        [$tenant,$actor] = $this->identity($r, $context);
        $data = $r->validate(['search' => 'nullable|string|max:120', 'personal' => 'nullable|boolean', 'page' => 'nullable|integer|min:1']);

        return response()->json($agents->replies($tenant, $actor, $r->boolean('personal'), $data['search'] ?? '')->paginate(30))->header('Cache-Control', 'private, no-store');
    }

    public function saveReply(Request $r, TenantContext $context, SupportUserAgents $agents)
    {
        [$tenant,$actor] = $this->identity($r, $context);
        $agents->saveReply($tenant, $actor, $r->all());

        return response()->noContent();
    }
}
