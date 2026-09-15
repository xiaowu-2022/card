<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\CardProviderEvent;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Models\UserCard;
use App\Support\Logging\PhotonPayLog;
use Illuminate\Support\Facades\DB;

final readonly class ProcessCardNotificationAction
{
    public function __construct(private RefreshManagedCardAction $refresh, private ManageCardAction $manage,
        private SyncProviderCardholderAction $holders, private SyncCardIssueAction $issues) {}

    public function execute(string $tenantId, string $eventId): void
    {
        $event = CardProviderEvent::query()->where('tenant_id', $tenantId)->whereKey($eventId)->firstOrFail();
        $logContext = ['tenant_id' => $tenantId, 'event_id' => $eventId];
        if ($event->status === 'PROCESSED') {
            PhotonPayLog::write('notification.already_processed', $logContext);

            return;
        }
        PhotonPayLog::write('notification.processing', $logContext);
        $failureCategory = null;
        try {
            if ($event->card_id) {
                // Unsigned headers and callback amounts never authorize settlement. Query exact provider trades.
                if ($event->transaction_id && $event->event_type === 'discard_recharge_return') {
                    $this->manage->settleCancellationReturn($tenantId, $event->user_id, $event->card_id, $event->transaction_id);
                }
                foreach (CardManagementOrder::query()->where('tenant_id', $tenantId)->where('card_id', $event->card_id)
                    ->whereIn('status', ['PROCESSING', 'UNKNOWN'])->get() as $order) {
                    $synced = $this->manage->sync($tenantId, $order->id);
                    if (! $synced->terminal()) {
                        throw new \RuntimeException('Card operation awaiting confirmation.');
                    }
                }
                $this->refresh->execute($tenantId, $event->user_id, $event->card_id, $event->transaction_id);
            } elseif ($event->cardholder_id) {
                $previousSync = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $event->user_id)
                    ->whereKey($event->cardholder_id)->firstOrFail()->synced_at;
                $holder = $this->holders->execute($tenantId, $event->user_id, $event->cardholder_id);
                // The existing interactive sync preserves the last known state on timeout.
                // An inbox event needs a fresh read, not merely that preserved state.
                if ($holder->synced_at === null || ($previousSync !== null && $holder->synced_at->lessThanOrEqualTo($previousSync))) {
                    throw new \RuntimeException('Cardholder status awaiting confirmation.');
                }
            } else {
                $issued = $this->issues->execute($tenantId, $event->issue_order_id, $event->user_id);
                if (! in_array($issued->status->value, ['SUCCEEDED', 'FAILED'], true)) {
                    throw new \RuntimeException('Card issue awaiting confirmation.');
                }
                if ($issued->status->value === 'SUCCEEDED') {
                    $card = UserCard::query()->where('tenant_id', $tenantId)->where('user_id', $event->user_id)->where('card_issue_order_id', $issued->id)->firstOrFail();
                    $this->refresh->execute($tenantId, $event->user_id, $card->id, $event->transaction_id);
                }
            }
            $status = 'PROCESSED';
        } catch (\Throwable $failure) {
            $failureCategory = PhotonPayLog::failure($failure);
            $status = 'RETRY';
        }
        $persistedStatus = DB::transaction(function () use ($tenantId, $eventId, $status): string {
            $row = CardProviderEvent::query()->where('tenant_id', $tenantId)->whereKey($eventId)->lockForUpdate()->firstOrFail();
            if ($row->status === 'PROCESSED') {
                return 'PROCESSED';
            }
            $row->forceFill(['status' => $status, 'attempts' => $row->attempts + 1, 'processed_at' => $status === 'PROCESSED' ? now() : null])->save();

            return $status;
        });
        PhotonPayLog::write($persistedStatus === 'PROCESSED' ? 'notification.processed' : 'notification.retry', $logContext + ['failure' => $failureCategory], $persistedStatus !== 'PROCESSED');
    }
}
