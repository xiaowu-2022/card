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
            ->whereNotNull('m.sender_admin_id')->whereColumn('m.sequence', '>', 'c.user_read_sequence')->count();
    }

    public function read(string $tenant, string $user, int $through): void
    {
        DB::transaction(function () use ($tenant, $user, $through) {
            $conversation = DB::table('support_conversations')->where('tenant_id', $tenant)->where('user_id', $user)->lockForUpdate()->first();
            abort_unless($conversation && $through <= $conversation->last_sequence, 422);
            if ($through > $conversation->user_read_sequence) {
                DB::table('support_conversations')->where('tenant_id', $tenant)->where('user_id', $user)->where('id', $conversation->id)
                    ->update(['user_read_sequence' => $through]);
            }
        });
    }
}
