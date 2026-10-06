<?php

namespace App\Application\Support;

use Illuminate\Support\Facades\DB;

final class SupportUnread
{
    public function count(string $tenant, string $user): int
    {
        return DB::table('support_messages as m')->join('support_conversations as c', function ($join) {
            $join->on('m.conversation_id', '=', 'c.id')->on('m.tenant_id', '=', 'c.tenant_id');
        })->where('c.tenant_id', $tenant)->where('c.user_id', $user)
            ->where(fn ($q) => $q->whereNotNull('m.sender_admin_id')->orWhereNotNull('m.sender_support_user_id')->orWhere('m.is_bot', true))->whereColumn('m.sequence', '>', 'c.user_read_sequence')->count();
    }

    public function agentCount(string $tenant, string $user): int
    {
        if (! app(SupportUserAgents::class)->enabled($tenant, $user)) return 0;

        return DB::table('support_messages as m')
            ->join('support_conversations as c', fn ($j) => $j->on('c.id', '=', 'm.conversation_id')->on('c.tenant_id', '=', 'm.tenant_id'))
            ->where('c.tenant_id', $tenant)->where('c.user_id', '!=', $user)
            ->whereNotNull('m.sender_user_id')
            ->whereRaw('m.sequence > COALESCE((SELECT r.through_sequence FROM support_agent_reads r WHERE r.tenant_id = m.tenant_id AND r.conversation_id = m.conversation_id AND r.user_id = ?), 0)', [$user])->count();
    }

    public function read(string $tenant, string $user, int $through, array $revisions = []): void
    {
        DB::transaction(function () use ($tenant, $user, $through, $revisions) {
            $conversation = DB::table('support_conversations')->where('tenant_id', $tenant)->where('user_id', $user)->lockForUpdate()->first();
            abort_unless($conversation && $through <= $conversation->last_sequence, 422);
            foreach ($revisions as $seen) {
                $message = DB::table('support_messages')->where('tenant_id', $tenant)->where('conversation_id', $conversation->id)->where('id', $seen['id'])->first();
                abort_unless($message && $message->sequence <= $through, 422);
                $exists = DB::table('support_message_revisions')->where('tenant_id', $tenant)->where('message_id', $message->id)->where('revision', $seen['revision'])->exists();
                abort_unless($exists, 422);
                $old = DB::table('support_message_revision_reads')->where('tenant_id', $tenant)->where('message_id', $message->id)->first();
                if (! $old || $old->revision < $seen['revision']) {
                    DB::table('support_message_revision_reads')->updateOrInsert(['tenant_id' => $tenant, 'message_id' => $message->id], [
                        'conversation_id' => $conversation->id, 'revision' => $seen['revision'], 'read_at' => now(),
                    ]);
                }
            }
            if ($through > $conversation->user_read_sequence) {
                DB::table('support_conversations')->where('tenant_id', $tenant)->where('user_id', $user)->where('id', $conversation->id)
                    ->update(['user_read_sequence' => $through]);
            }
        });
    }
}
