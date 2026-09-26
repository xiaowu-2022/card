<?php

namespace App\Application\Inbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class InboxDelivery
{
    public function attempt(string $tenant, string $id): bool
    {
        try {
            DB::transaction(function () use ($tenant, $id) {
                $event = DB::table('inbox_events')->where('tenant_id', $tenant)->where('id', $id)->lockForUpdate()->first();
                if (! $event || $event->delivered_at) {
                    return;
                }
                // Serialize delivery and read-all for one recipient: a new arrival cannot be swallowed.
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['inbox:'.$tenant.':'.$event->user_id]);
                DB::table('inbox_receipts')->insertOrIgnore(['tenant_id' => $tenant, 'user_id' => $event->user_id,
                    'event_id' => $id, 'delivered_at' => now()]);
                DB::table('inbox_events')->where('tenant_id', $tenant)->where('id', $id)->update(['delivered_at' => now()]);
            }, 3);

            return true;
        } catch (\Throwable $e) {
            // Never propagate a post-commit delivery failure into financial retry handling.
            Log::warning('Inbox delivery pending', ['tenant_id' => $tenant, 'event_id' => $id, 'error_class' => $e::class]);

            return false;
        }
    }

    public function recover(?string $tenant = null, int $limit = 500): int
    {
        $count = 0;
        foreach (DB::table('inbox_events')->whereNull('delivered_at')->when($tenant, fn ($q) => $q->where('tenant_id', $tenant))
            ->orderBy('created_at')->orderBy('id')->limit($limit)->get(['id', 'tenant_id']) as $event) {
            if ($this->attempt($event->tenant_id, $event->id)) {
                $count++;
            }
        }

        return $count;
    }
}
