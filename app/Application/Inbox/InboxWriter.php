<?php

namespace App\Application\Inbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class InboxWriter
{
    public function record(string $tenant, string $user, string $key, string $template, array $parameters = [], ?string $href = null, mixed $occurredAt = null): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Inbox intent must share its business transaction.');
        }
        if (! in_array($template, InboxTemplates::KEYS, true)) {
            throw new LogicException('Unknown inbox template.');
        }
        $id = (string) Str::uuid();
        $inserted = DB::table('inbox_events')->insertOrIgnore([
            'id' => $id, 'tenant_id' => $tenant, 'user_id' => $user, 'event_key' => $key,
            'kind' => 'BUSINESS', 'template' => $template, 'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'href' => $href, 'occurred_at' => $occurredAt ?? now(), 'created_at' => now(),
        ]);
        if ($inserted) {
            DB::afterCommit(fn () => app(InboxDelivery::class)->attempt($tenant, $id));
        }
    }
}
