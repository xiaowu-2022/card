<?php

namespace App\Application\Support;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SupportQuickReplies
{
    public function visible(string $tenant, string $actor, string $search = '')
    {
        return DB::table('support_quick_replies')->whereNull('archived_at')->where(fn ($q) => $q->where('tenant_id', $tenant)->orWhere('admin_id', $actor))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('title', 'ilike', '%'.addcslashes($search, '%_').'%')->orWhere('body', 'ilike', '%'.addcslashes($search, '%_').'%')))
            ->orderBy('title')->orderBy('id');
    }

    public function save(string $actor, ?string $tenant, array $input): void
    {
        abort_unless($tenant, 410);
        app(SupportAccess::class)->platform($actor, 'support.replies.manage');
        $data = Validator::make($input, ['id' => 'required|uuid', 'title' => 'required|string|max:100', 'body' => 'required|string|max:2000', 'revision' => 'required|integer|min:0', 'archived' => 'required|boolean'])->validate();
        abort_if(trim($data['title']) === '' || trim($data['body']) === '', 422);
        DB::transaction(function () use ($actor, $tenant, $data) {
            Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            app(SupportAccess::class)->platform($actor, 'support.replies.manage');
            $row = DB::table('support_quick_replies')->where('id', $data['id'])->lockForUpdate()->first();
            if ($row) {
                abort_unless($tenant ? $row->tenant_id === $tenant : $row->admin_id === $actor, 404);
            }
            abort_if((int) ($row?->revision ?? 0) !== (int) $data['revision'], 409);
            $values = ['title' => trim($data['title']), 'body' => trim($data['body']), 'revision' => (int) ($row?->revision ?? 0) + 1, 'archived_at' => $data['archived'] ? now() : null, 'updated_at' => now()];
            if ($row) {
                DB::table('support_quick_replies')->where('id', $row->id)->update($values);
            } else {
                DB::table('support_quick_replies')->insert($values + ['id' => $data['id'], 'tenant_id' => $tenant, 'admin_id' => $tenant ? null : $actor, 'created_at' => now()]);
            }
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor, 'SUPPORT_QUICK_REPLY_SAVED', 'support_quick_reply', $data['id'], null, ['revision' => $values['revision'], 'archived' => $data['archived']]);
        });
    }
}
