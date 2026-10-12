<?php

namespace App\Application\Support;

use Illuminate\Support\Facades\DB;

final class SupportAdminUnread
{
    public function messages(string $admin)
    {
        return DB::table('support_messages as unread')
            ->whereNotNull('unread.sender_user_id')
            ->whereRaw('unread.sequence > COALESCE((SELECT r.through_sequence FROM support_admin_reads r WHERE r.tenant_id = unread.tenant_id AND r.conversation_id = unread.conversation_id AND r.admin_id = ?), 0)', [$admin]);
    }

    public function rowCount(string $admin)
    {
        return $this->messages($admin)->whereColumn('unread.tenant_id', 'support_conversations.tenant_id')
            ->whereColumn('unread.conversation_id', 'support_conversations.id')->selectRaw('COUNT(*)');
    }

    public function count(string $admin, ?string $tenant = null): int
    {
        $this->authorize($admin, $tenant);

        return $this->messages($admin)
            ->join('support_conversations as c', fn ($j) => $j->on('c.id', '=', 'unread.conversation_id')->on('c.tenant_id', '=', 'unread.tenant_id'))
            ->join('users as u', fn ($j) => $j->on('u.id', '=', 'c.user_id')->on('u.tenant_id', '=', 'c.tenant_id'))
            ->when($tenant, fn ($q) => $q->where('c.tenant_id', $tenant))->count();
    }

    public function read(string $admin, string $tenant, string $conversation, int $through, bool $platform): void
    {
        DB::transaction(function () use ($admin, $tenant, $conversation, $through, $platform) {
            $this->authorize($admin, $platform ? null : $tenant);
            $row = DB::table('support_conversations')->where('tenant_id', $tenant)->where('id', $conversation)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($through >= 0 && $through <= $row->last_sequence, 422);
            DB::statement('INSERT INTO support_admin_reads (tenant_id, conversation_id, admin_id, through_sequence) VALUES (?, ?, ?, ?) ON CONFLICT (tenant_id, conversation_id, admin_id) DO UPDATE SET through_sequence = GREATEST(support_admin_reads.through_sequence, EXCLUDED.through_sequence)', [$tenant, $conversation, $admin, $through]);
        });
    }

    private function authorize(string $admin, ?string $tenant): void
    {
        $access = app(SupportAccess::class);
        if ($tenant === null) $access->platform($admin);
        else $access->admin($tenant, $admin);
    }
}
