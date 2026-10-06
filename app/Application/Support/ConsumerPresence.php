<?php

namespace App\Application\Support;

use Illuminate\Support\Facades\DB;

final class ConsumerPresence
{
    // Presence is a recent foreground heartbeat, never proof of message reading.
    public function touch(string $tenant, string $user): void
    {
        DB::table('consumer_presence')->upsert([
            ['tenant_id' => $tenant, 'user_id' => $user, 'seen_at' => now()],
        ], ['tenant_id', 'user_id'], ['seen_at']);
    }

    public function online(string $tenant, string $user): bool
    {
        return in_array($user, $this->onlineUsers($tenant, [$user]), true);
    }

    public function onlineUsers(string $tenant, array $users): array
    {
        return DB::table('consumer_presence as p')->join('users as u', function ($j) {
            $j->on('u.id', '=', 'p.user_id')->on('u.tenant_id', '=', 'p.tenant_id');
        })->where('p.tenant_id', $tenant)->whereIn('p.user_id', $users)
            ->where('u.status', 'ACTIVE')->where('p.seen_at', '>', now()->subSeconds(75))->pluck('p.user_id')->all();
    }
}
