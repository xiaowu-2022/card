<?php

namespace App\Console\Commands;

use App\Domain\Card\Models\CardProviderEvent;
use App\Domain\Card\Models\UserCard;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class InspectCardNotification extends Command
{
    protected $signature = 'cards:notification-inspect {tenant} {event}';

    protected $description = 'Read one tenant-scoped notification and its cached card state without processing it';

    public function handle(): int
    {
        $tenantId = $this->argument('tenant');
        $eventId = $this->argument('event');
        if (! Str::isUuid($tenantId) || ! Str::isUuid($eventId)) {
            $this->error('Tenant and event must be UUIDs.');

            return self::INVALID;
        }
        $event = CardProviderEvent::query()->where('tenant_id', $tenantId)->whereKey($eventId)->first();
        if ($event === null) {
            $this->error('Notification not found in the selected company.');

            return self::FAILURE;
        }
        $cardQuery = UserCard::query()->where('tenant_id', $tenantId)->where('user_id', $event->user_id);
        $card = $event->card_id ? $cardQuery->whereKey($event->card_id)->first()
            : ($event->issue_order_id ? $cardQuery->where('card_issue_order_id', $event->issue_order_id)->first() : null);
        // Explicit allowlist: no payloads, provider identifiers, credentials or personal data.
        $this->line(json_encode([
            'tenant_id' => $tenantId, 'event_id' => $eventId,
            'event_status' => $event->status, 'attempts' => (int) $event->attempts,
            'received_at' => $event->created_at?->toIso8601String(),
            'updated_at' => $event->updated_at?->toIso8601String(), 'processed_at' => $event->processed_at,
            'has_transaction' => $event->transaction_id !== null,
            'card_id' => $card?->id, 'card_balance' => $card?->provider_balance,
            'card_currency' => $card?->card_currency,
            'balance_synced_at' => $card?->provider_balance_synced_at?->toIso8601String(),
            'notification_execution' => 'inline',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->info('Read only. Notifications synchronize inline; automatic card recovery is disabled.');

        return self::SUCCESS;
    }
}
