<?php

namespace App\Console\Commands;

use App\Application\Card\ManageCardAction;
use App\Application\Card\ProcessCardNotificationAction;
use App\Application\Card\RefreshManagedCardAction;
use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\CardProviderEvent;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class RecoverCardManagement extends Command
{
    protected $signature = 'cards:recover {--tenant=}';

    protected $description = 'Query unresolved card operations and retry the verified notification inbox; never resend mutations.';

    public function handle(ManageCardAction $manage, ProcessCardNotificationAction $events, RefreshManagedCardAction $refresh, CardProviderInterface $provider): int
    {
        if (! $provider->available()) {
            $this->warn('Card provider is not configured.');

            return self::SUCCESS;
        }
        $selected = $this->option('tenant');
        if ($selected && ! Str::isUuid($selected)) {
            return self::INVALID;
        }
        foreach (Tenant::query()->when($selected, fn ($q) => $q->whereKey($selected))->orderBy('id')->cursor() as $tenant) {
            foreach (CardManagementOrder::query()->where('tenant_id', $tenant->id)->whereIn('status', ['QUOTING', 'QUOTED', 'PROCESSING', 'UNKNOWN'])->oldest('updated_at')->limit(100)->get() as $order) {
                try {
                    $manage->sync($tenant->id, $order->id);
                } catch (\Throwable) { /* Preserve intent/hold for next query. */
                }
            }
            foreach (CardProviderEvent::query()->where('tenant_id', $tenant->id)->whereIn('status', ['PENDING', 'RETRY'])->oldest('updated_at')->limit(100)->get() as $event) {
                $events->execute($tenant->id, $event->id);
            }
            foreach (UserCard::query()->where('tenant_id', $tenant->id)->withoutTestReferences()->orderByRaw('provider_balance_synced_at ASC NULLS FIRST')->limit(20)->get() as $card) {
                try {
                    // Recover a lost cancellation-return callback from trusted trade history, not a balance difference.
                    if (CardManagementOrder::query()->where('tenant_id', $tenant->id)->where('card_id', $card->id)->where('kind', 'CANCEL')->whereIn('status', ['SUCCEEDED', 'PROCESSING', 'UNKNOWN'])->exists()) {
                        $page = $provider->getTransactionPage($card->provider_card_id, 1, 100);
                        foreach ($page->items as $item) {
                            if ($item->type === 'transfer_out' && $item->state === 'completed') {
                                try {
                                    $manage->settleCancellationReturn($tenant->id, $card->user_id, $card->id, $item->providerTransactionId);
                                } catch (\Throwable) { /* Only exact cancellation returns can settle. */
                                }
                            }
                        }
                    }
                    $refresh->execute($tenant->id, $card->user_id, $card->id);
                } catch (\Throwable) { /* Next pass retries authoritative reads only. */
                }
            }
        }
        $this->info('Card recovery pass completed.');

        return self::SUCCESS;
    }
}
