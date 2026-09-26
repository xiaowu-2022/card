<?php

namespace App\Application\Inbox;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BroadcastMessages
{
    private function allowed(AdminUser $actor): void
    {
        abort_unless($actor->status->value === 'ACTIVE' && app(AuthorizationService::class)->allows($actor, ScopeType::Platform, null, 'notifications.send'), 403);
    }

    private function recipients(string $tenant, array $data): array
    {
        abort_unless(DB::table('tenants')->where('id', $tenant)->exists(), 404);
        $selected = array_values(array_unique($data['users'] ?? []));
        $ids = DB::table('users')->where('tenant_id', $tenant)
            ->when($data['audience'] === 'selected', fn ($q) => $q->whereIn('id', $selected))->orderBy('id')->pluck('id')->all();
        if (! $ids || ($data['audience'] === 'selected' && count($ids) !== count($selected))) {
            throw ValidationException::withMessages(['users' => 'Choose recipients from this company.']);
        }

        return $ids;
    }

    private function hash(string $tenant, array $data): string
    {
        $ids = array_values(array_unique($data['users'] ?? []));
        sort($ids);

        return hash('sha256', json_encode([$tenant, $data['audience'], $data['title'], $data['body'], $data['audience'] === 'all' ? [] : $ids], JSON_THROW_ON_ERROR));
    }

    public function preview(AdminUser $actor, string $tenant, array $data): array
    {
        $this->allowed($actor);
        $ids = $this->recipients($tenant, $data);

        return ['count' => count($ids), 'token' => Crypt::encryptString(json_encode(['actor' => $actor->id, 'hash' => $this->hash($tenant, $data),
            'audience' => hash('sha256', implode(',', $ids)), 'expires' => now()->addMinutes(10)->timestamp], JSON_THROW_ON_ERROR))];
    }

    public function send(AdminUser $actor, string $tenant, array $data): string
    {
        return DB::transaction(function () use ($actor, $tenant, $data) {
            DB::table('tenants')->where('id', $tenant)->lockForUpdate()->firstOrFail();
            $locked = AdminUser::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            DB::table('admin_memberships')->where('admin_user_id', $actor->id)->where('scope_type', 'PLATFORM')->lockForUpdate()->get();
            $this->allowed($locked);
            $hash = $this->hash($tenant, $data);
            $existing = DB::table('inbox_broadcasts')->where('id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->tenant_id === $tenant && $existing->actor_id === $actor->id && hash_equals($existing->intent_hash, $hash), 409);

                return $existing->id;
            }
            try {
                $token = json_decode(Crypt::decryptString($data['token']), true, flags: JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['token' => 'Preview the notification again before sending.']);
            }
            $ids = $this->recipients($tenant, $data);
            if (($token['actor'] ?? '') !== $actor->id || ($token['hash'] ?? '') !== $hash || ($token['expires'] ?? 0) < now()->timestamp
                || ($token['audience'] ?? '') !== hash('sha256', implode(',', $ids))) {
                throw ValidationException::withMessages(['token' => 'The recipients or content changed. Preview again.']);
            }
            $id = $data['request_id'];
            $now = now();
            DB::table('inbox_broadcasts')->insert(['id' => $id, 'tenant_id' => $tenant, 'actor_id' => $actor->id, 'intent_hash' => $hash,
                'audience' => $data['audience'], 'title' => $data['title'], 'body' => $data['body'], 'recipient_count' => count($ids), 'created_at' => $now]);
            foreach (array_chunk($ids, 250) as $chunk) {
                DB::table('inbox_events')->insert(array_map(fn ($user) => ['id' => (string) Str::uuid(), 'tenant_id' => $tenant,
                    'user_id' => $user, 'broadcast_id' => $id, 'event_key' => 'broadcast:'.$id, 'kind' => 'PLATFORM', 'parameters' => '{}',
                    'occurred_at' => $now, 'created_at' => $now], $chunk));
            }
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor->id, 'INBOX_BROADCAST_SENT', 'inbox_broadcast', $id, null,
                ['recipient_count' => count($ids), 'audience' => $data['audience']], $id);

            // No external sends. The bounded scheduled worker delivers the durable recipient snapshot.
            return $id;
        }, 3);
    }
}
