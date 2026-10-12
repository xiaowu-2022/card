<?php

namespace App\Application\Support;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SupportRealtime
{
    public function enabled(): bool
    {
        return (bool) config('support-realtime.enabled') && filled(config('broadcasting.connections.reverb.key'))
            && filled(config('broadcasting.connections.reverb.secret')) && filled(config('broadcasting.connections.reverb.app_id'));
    }

    public static function userChannel(string $tenant, string $user): string
    {
        return 'private-support.user.'.hash('sha256', $tenant.':'.$user);
    }

    public static function teamChannel(string $tenant): string
    {
        return 'private-support.team.'.hash('sha256', $tenant);
    }

    public static function platformChannel(): string
    {
        return 'private-support.platform';
    }

    public static function adminChannel(string $admin): string
    {
        return 'private-support.admin.'.hash('sha256', $admin);
    }

    public function conversation(string $tenant, string $user): void
    {
        $this->notify([self::userChannel($tenant, $user), self::teamChannel($tenant), self::platformChannel()]);
    }

    public function notify(array $channels): void
    {
        if (! $this->enabled()) {
            return;
        }
        // Observers run inside the existing business transaction: never publish rolled-back evidence.
        DB::afterCommit(function () use ($channels): void {
            try {
                Broadcast::connection('reverb')->broadcast($channels, 'support.changed', ['refresh' => true]);
            } catch (\Throwable) {
                // A push failure must never turn a committed message into a failed/repeated send.
                Log::warning('Support realtime notification unavailable; clients recover through scoped reads.');
            }
        });
    }
}
