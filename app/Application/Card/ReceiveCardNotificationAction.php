<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\CardProviderEvent;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Models\UserCard;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Providers\Card\PhotonPayNotificationVerifier;
use App\Jobs\ProcessCardNotification;
use App\Support\Errors\DomainException;
use App\Support\Logging\PhotonPayLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ReceiveCardNotificationAction
{
    public function __construct(private PhotonPayNotificationVerifier $verifier) {}

    public function execute(#[\SensitiveParameter] string $body, string $signature, string $category, string $type): void
    {
        $facts = $this->verifier->verify($body, $signature, $category, $type);
        PhotonPayLog::write('webhook.verified', ['category' => $facts['category'], 'notification_type_ref' => PhotonPayLog::reference($facts['event_type'])]);
        // Sole global lookups: trusted provider-resource mappings, never a body tenant or Host.
        $resource = $facts['cardId'] ? UserCard::query()->where('provider', 'PHOTONPAY')->where('provider_card_id', $facts['cardId'])->first() : null;
        $column = 'card_id';
        if (! $resource && ! $facts['cardId'] && ! $facts['requestId'] && $facts['cardholderId']) {
            $resource = ProviderCardholder::query()->where('provider', 'PHOTONPAY')->where('provider_cardholder_id', $facts['cardholderId'])->first();
            $column = 'cardholder_id';
        }
        if (! $resource && $facts['requestId'] && Str::isUuid($facts['requestId'])) {
            $resource = CardIssueOrder::query()->where('provider', 'PHOTONPAY')->whereKey($facts['requestId'])->first();
            $column = 'issue_order_id';
        }
        if (! $resource) {
            throw new DomainException('CARD_WEBHOOK_UNMAPPED', 'Notification mapping is not available yet.', 404);
        }
        $event = DB::transaction(function () use ($facts, $resource, $column): CardProviderEvent {
            // Serialize duplicate deliveries on the trusted tenant row without retaining raw bodies.
            Tenant::query()->whereKey($resource->tenant_id)->lockForUpdate()->firstOrFail();
            $existing = CardProviderEvent::query()->where('tenant_id', $resource->tenant_id)->where('event_digest', $facts['digest'])->first();
            if ($existing) {
                return $existing;
            }
            $event = new CardProviderEvent;
            $event->forceFill(['tenant_id' => $resource->tenant_id, 'user_id' => $resource->user_id, $column => $resource->id,
                'event_digest' => $facts['digest'], 'category' => $facts['category'], 'event_type' => $facts['event_type'],
                'transaction_id' => $facts['transactionId'], 'request_id' => $facts['requestId'], 'status' => 'PENDING'])->save();

            return $event;
        });
        $logContext = ['tenant_id' => $event->tenant_id, 'event_id' => $event->id, 'resource_id' => $resource->id];
        PhotonPayLog::write('notification.persisted', $logContext + ['duplicate' => ! $event->wasRecentlyCreated]);
        if ($event->status !== 'PROCESSED') {
            try {
                ProcessCardNotification::dispatch($event->tenant_id, $event->id);
                PhotonPayLog::write('notification.dispatched', $logContext);
            } catch (\Throwable $failure) { // Durable inbox remains available for cards:recover.
                PhotonPayLog::write('notification.dispatch_failed', $logContext + ['failure' => PhotonPayLog::failure($failure)], true);
            }
        }
    }
}
