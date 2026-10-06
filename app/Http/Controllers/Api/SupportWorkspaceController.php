<?php

namespace App\Http\Controllers\Api;

use App\Application\Support\SendSupportMessageAction;
use App\Application\Support\SupportBot;
use App\Application\Support\SupportChatQuery;
use App\Application\Support\SupportImageStorage;
use App\Application\Support\SupportUserAgents;
use App\Domain\Support\Models\SupportConversation;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendSupportMessageRequest;
use Illuminate\Http\Request;

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
        $status = $data['status'] ?? 'awaiting';
        $rows = SupportConversation::query()->where('support_conversations.tenant_id', $tenant)->where('support_conversations.user_id', '!=', $actor)
            ->join('users as u', fn ($j) => $j->on('u.id', '=', 'support_conversations.user_id')->on('u.tenant_id', '=', 'support_conversations.tenant_id'))
            ->leftJoin('user_profiles as p', fn ($j) => $j->on('p.user_id', '=', 'u.id')->on('p.tenant_id', '=', 'u.tenant_id'))
            ->when($status === 'awaiting', fn ($q) => $q->where(fn ($q) => $q->where('mode', 'WAITING')->orWhere(fn ($q) => $q->where('mode', 'HUMAN')->where('last_sender', 'USER'))))
            ->when(in_array($status, ['BOT', 'WAITING', 'HUMAN']), fn ($q) => $q->where('mode', $status))
            ->when($data['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('u.account_id', 'like', '%'.addcslashes($v, '%_').'%')->orWhere('u.email', 'ilike', '%'.addcslashes($v, '%_').'%')->orWhere('p.display_name', 'ilike', '%'.addcslashes($v, '%_').'%')))
            ->select('support_conversations.*', 'u.account_id', 'p.display_name')->orderByDesc('support_conversations.updated_at')->orderBy('support_conversations.id')->paginate(30)
            ->through(fn ($row) => ['id' => $row->id, 'accountId' => $row->account_id, 'name' => $row->display_name, 'mode' => $row->mode, 'updatedAt' => $row->updated_at->toIso8601String()]);

        return response()->json(['inbox' => $rows, 'profile' => app(SupportUserAgents::class)->profile($tenant, $actor)])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, TenantContext $context, string $conversation, SupportChatQuery $query)
    {
        [$tenant,$actor] = $this->identity($r, $context);
        $r->validate(['before' => 'nullable|integer|min:0|max:2147483647']);
        $row = app(SupportUserAgents::class)->conversation($tenant, $actor, $conversation);
        $chat = $query->thread($tenant, $row, $r->integer('before'), true);
        foreach ($chat['messages'] as &$message) {
            if ($message['imageUrl'] && str_starts_with($message['imageUrl'], '/admin/')) {
                $message['imageUrl'] = '/support-workspace/images/'.$message['id'];
            }
        }

        return response()->json($chat)->header('Cache-Control', 'private, no-store');
    }

    public function send(SendSupportMessageRequest $r, TenantContext $context, string $conversation, SendSupportMessageAction $action)
    {
        [$tenant,$actor] = $this->identity($r, $context);
        app(SupportUserAgents::class)->conversation($tenant, $actor, $conversation);
        $action->agent($tenant, $actor, $conversation, $r->validated('request_id'), $r->validated('support_message') ?? '', $r->resolvedImages($tenant, $actor, 'support', ['support_image'])['support_image'] ?? null);

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

        return response()->json($agents->replies($tenant, $actor, $r->boolean('personal'), $data['search'] ?? '')->paginate(30))->header('Cache-Control','private, no-store');
    }

    public function saveReply(Request $r,TenantContext $context,SupportUserAgents $agents)
    {
        [$tenant,$actor] = $this->identity($r,$context);
        $agents->saveReply($tenant,$actor,$r->all());

        return response()->noContent();
    }
}
