<?php

namespace App\Application\Support;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Support\Models\SupportConversation;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SupportBot
{
    public function settings(string $tenant): array
    {
        $row = DB::table('support_bot_settings')->where('tenant_id', $tenant)->first();

        return ['enabled' => (bool) ($row?->enabled ?? false), 'revision' => (int) ($row?->revision ?? 0)];
    }

    public function metadata(string $tenant, ?SupportConversation $conversation): array
    {
        $enabled = $this->settings($tenant)['enabled'];

        return ['mode' => $conversation?->mode ?? ($enabled ? 'BOT' : 'HUMAN'),
            'revision' => $conversation?->revision ?? 0, 'botEnabled' => $enabled, 'humanSupport' => app(SupportHours::class)->availability($tenant)];
    }

    public function configure(string $tenant, string $actor, bool $enabled, int $revision): void
    {
        app(SupportAccess::class)->platform($actor, 'support.bot.manage');
        DB::transaction(function () use ($tenant, $actor, $enabled, $revision) {
            Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            app(SupportAccess::class)->platform($actor, 'support.bot.manage');
            $before = $this->settings($tenant);
            abort_if($before['revision'] !== $revision, 409, 'Settings changed. Refresh before saving.');
            DB::table('support_bot_settings')->updateOrInsert(['tenant_id' => $tenant], ['enabled' => $enabled, 'revision' => $revision + 1, 'updated_at' => now()]);
            if (! $enabled) {
                // Tenant lock serializes this with send, handoff and finish; no history changes.
                foreach (SupportConversation::where('tenant_id', $tenant)->where('mode', 'BOT')->lockForUpdate()->get() as $conversation) {
                    $conversation->update(['mode' => 'HUMAN', 'revision' => $conversation->revision + 1]);
                    $this->record($conversation, $actor, 'DISABLE', (string) Str::uuid(), '', 'BOT', 'HUMAN');
                }
            }
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor, 'SUPPORT_BOT_CONFIGURED', 'tenant', $tenant, $before, ['enabled' => $enabled, 'revision' => $revision + 1]);
        });
    }

    /** Called only for a newly persisted user message, under the existing owner/conversation locks. */
    public function reply(SupportConversation $conversation, string $messageId, string $text): void
    {
        if ($conversation->mode !== 'BOT' || ! $this->settings($conversation->tenant_id)['enabled']) {
            return;
        }
        $answer = app(SupportFaqs::class)->match($conversation->tenant_id, $text)['answer'];
        $fallback = app(SupportHours::class)->availability($conversation->tenant_id)['available'] ? '暂未找到可靠的相关答案。请换一种问法，或点击“转人工”联系客户服务。' : '暂未找到可靠的相关答案。当前客服不在线，请在服务时间内转人工。';
        $this->append($conversation, $answer['answer'] ?? $fallback, $messageId, $answer);
    }

    public function takeover(SupportConversation $conversation, string $actor, string $request, string $actorKind = 'ADMIN'): void
    {
        if ($conversation->mode === 'HUMAN') {
            return;
        }
        $from = $conversation->mode;
        $conversation->mode = 'HUMAN';
        $this->record($conversation, $actor, 'TAKEOVER', $request, '', $from, 'HUMAN', $actorKind);
    }

    public function handoff(string $tenant, string $user, string $request): void
    {
        $this->transition($tenant, $user, $user, $request, null, false);
    }

    public function finish(string $tenant, string $user, string $actor, string $request, int $revision, bool $platform): void
    {
        $platform ? app(SupportAccess::class)->platform($actor, 'support.send') : app(SupportAccess::class)->admin($tenant, $actor);
        $this->transition($tenant, $user, $actor, $request, $revision, true, $platform);
    }

    public function finishAgent(string $tenant, string $actor, string $conversation, string $request, int $revision): void
    {
        $row = app(SupportUserAgents::class)->conversation($tenant, $actor, $conversation);
        $this->transition($tenant, $row->user_id, $actor, $request, $revision, true, false, true);
    }

    private function transition(string $tenant, string $user, string $actor, string $request, ?int $revision, bool $finish, bool $platform = false, bool $agent = false): void
    {
        abort_unless(Str::isUuid($request), 422);
        $action = $finish ? 'FINISH' : 'HANDOFF';
        $intent = hash('sha256', json_encode([$action, $actor, $revision, $platform, ...($agent ? ['USER_AGENT'] : [])]));
        DB::transaction(function () use ($tenant, $user, $actor, $request, $revision, $finish, $platform, $action, $intent, $agent) {
            $company = Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $customer = User::where('tenant_id', $tenant)->whereKey($user)->lockForUpdate()->firstOrFail();
            abort_unless($company->status->value === 'ACTIVE' && $customer->status->value === 'ACTIVE', 403);
            if ($finish) {
                if ($agent) {
                    app(SupportUserAgents::class)->authorize($tenant, $actor);
                    abort_if($actor === $user, 404);
                } else {
                    $platform ? app(SupportAccess::class)->platform($actor, 'support.send') : app(SupportAccess::class)->admin($tenant, $actor);
                }
            }
            $prior = DB::table('support_transitions')->where('tenant_id', $tenant)->where('user_id', $user)->where('request_id', $request)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->intent_hash, $intent), 409);

                return;
            }
            if (! $finish) {
                app(SupportHours::class)->assertAvailable($tenant);
            }
            $conversation = SupportConversation::where('tenant_id', $tenant)->where('user_id', $user)->lockForUpdate()->first();
            $enabled = $this->settings($tenant)['enabled'];
            if (! $conversation) {
                abort_if($finish || ! $enabled, 409);
                $conversation = SupportConversation::create(['tenant_id' => $tenant, 'user_id' => $user, 'last_sequence' => 0, 'last_sender' => 'USER', 'mode' => 'BOT', 'revision' => 1]);
            }
            if ($finish) {
                abort_if($conversation->revision !== $revision, 409, 'Conversation changed. Read the latest messages before ending service.');
            }
            $from = $conversation->mode;
            if ($finish && $from !== 'BOT') {
                $conversation->mode = $enabled ? 'BOT' : 'HUMAN';
                $this->append($conversation, $enabled ? '本轮人工接待已结束。后续问题将由客服助手先为您解答，您可以随时转人工。' : '本轮人工接待已结束。如有其他问题，您可以继续留言。');
            } elseif (! $finish && $from === 'BOT') {
                $conversation->mode = 'WAITING';
                $this->append($conversation, '已转人工，请等待客服回复。您可以继续补充问题。');
            }
            $this->record($conversation, $actor, $action, $request, $intent, $from, $conversation->mode, $agent || ! $finish ? 'USER' : 'ADMIN');
        });
    }

    private function append(SupportConversation $conversation, string $text, ?string $reply = null, ?array $faq = null): void
    {
        $sequence = $conversation->last_sequence + 1;
        SupportMessage::create(['tenant_id' => $conversation->tenant_id, 'conversation_id' => $conversation->id,
            'sequence' => $sequence, 'is_bot' => true, 'reply_to_id' => $reply, 'faq_id' => $faq['id'] ?? null,
            'faq_revision' => $faq['revision'] ?? null, 'request_id' => (string) Str::uuid(),
            'support_name' => '客服助手', 'support_message' => $text]);
        $conversation->fill(['last_sequence' => $sequence, 'last_sender' => 'BOT', 'revision' => $conversation->revision + 1])->save();
    }

    private function record(SupportConversation $conversation, string $actor, string $action, string $request, string $intent, string $from, string $to, string $actorKind = 'ADMIN'): void
    {
        abort_if(DB::table('support_transitions')->where('tenant_id', $conversation->tenant_id)->where('user_id', $conversation->user_id)->where('request_id', $request)->exists(), 409, 'This support request was already used.');
        DB::table('support_transitions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $conversation->tenant_id,
            'user_id' => $conversation->user_id, 'conversation_id' => $conversation->id, 'request_id' => $request,
            'intent_hash' => $intent, 'action' => $action, 'actor_id' => $actor, 'actor_kind' => $actorKind, 'from_mode' => $from, 'to_mode' => $to, 'created_at' => now()]);
        app(AuditLogger::class)->record($conversation->tenant_id, $actorKind, $actor,
            'SUPPORT_'.$action, 'support_conversation', $conversation->id, ['mode' => $from], ['mode' => $to], $request);
    }
}
