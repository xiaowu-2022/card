<?php

namespace App\Application\Support;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Support\Models\SupportConversation;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class SupportMessageChanges
{
    public function change(string $tenant, string $actor, string $conversation, string $message, array $input, ?bool $platform = null): void
    {
        $data = Validator::make($input, [
            'request_id' => 'required|uuid', 'revision' => 'required|integer|min:0',
            'operation' => 'required|in:EDIT,DELETE', 'text' => 'nullable|string|max:2000',
        ])->validate();
        $body = trim($data['text'] ?? '');
        abort_if($data['operation'] === 'EDIT' && $body === '', 422);
        abort_if($data['operation'] === 'DELETE' && $body !== '', 422);
        DB::transaction(function () use ($tenant, $actor, $conversation, $message, $data, $body, $platform) {
            Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            if ($platform === null) {
                app(SupportUserAgents::class)->conversation($tenant, $actor, $conversation);
            } elseif ($platform) {
                app(SupportAccess::class)->platform($actor, 'support.send');
            } else {
                app(SupportAccess::class)->admin($tenant, $actor);
            }
            SupportConversation::where('tenant_id', $tenant)->whereKey($conversation)->lockForUpdate()->firstOrFail();
            $row = SupportMessage::where('tenant_id', $tenant)->where('conversation_id', $conversation)->findOrFail($message);
            abort_unless(! $row->is_bot && ($platform === null ? $row->sender_support_user_id : $row->sender_admin_id) === $actor, 403);
            $actorColumn = $platform === null ? 'actor_user_id' : 'actor_admin_id';
            $existing = DB::table('support_message_revisions')->where('tenant_id', $tenant)->where($actorColumn, $actor)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->message_id === $message && $existing->operation === $data['operation']
                    && $existing->revision === (int) $data['revision'] + 1
                    && ($existing->body === null ? '' : Crypt::decryptString($existing->body)) === $body, 409);

                return;
            }
            $last = $this->latest($tenant, $message);
            abort_if(($last?->revision ?? 0) !== (int) $data['revision'] || $last?->operation === 'DELETE', 409);
            $revision = (int) $data['revision'] + 1;
            DB::table('support_message_revisions')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'conversation_id' => $conversation,
                'message_id' => $message, $actorColumn => $actor, 'request_id' => $data['request_id'],
                'revision' => $revision, 'operation' => $data['operation'],
                'body' => $data['operation'] === 'EDIT' ? Crypt::encryptString($body) : null, 'created_at' => now(),
            ]);
            app(SupportRealtime::class)->conversation($tenant, SupportConversation::where('tenant_id', $tenant)->findOrFail($conversation)->user_id);
            app(AuditLogger::class)->record($tenant, $platform === null ? 'USER' : 'ADMIN', $actor, 'SUPPORT_MESSAGE_'.$data['operation'], 'support_message', $message,
                ['revision' => $revision - 1], ['revision' => $revision]);
        });
    }

    public function latest(string $tenant, string $message): ?object
    {
        return DB::table('support_message_revisions')->where('tenant_id', $tenant)->where('message_id', $message)->orderByDesc('revision')->first();
    }
}
