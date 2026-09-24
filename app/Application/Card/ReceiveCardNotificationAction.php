<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\CardProviderEvent;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Providers\Card\PhotonPayNotificationVerifier;
use App\Support\Errors\DomainException;
use App\Support\Logging\PhotonPayLog;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ReceiveCardNotificationAction
{
    public function __construct(private PhotonPayNotificationVerifier $verifier, private ProcessCardNotificationAction $process) {}

    public function execute(#[\SensitiveParameter] string $body, string $signature, string $category, string $type, ?string $accountId = null): void
    {
        $account = app(PhotonPayWebhookAccount::class)->resolve($body, $accountId);
        $facts = $this->verifier->verify($body, $signature, $category, $type, Crypt::decryptString($account->photonpay_webhook_key_encrypted));
        $products = CardProduct::where('card_provider_reference_id', $account->id)->select('id');
        PhotonPayLog::write('webhook.verified', ['category' => $facts['category'], 'notification_type_ref' => PhotonPayLog::reference($facts['event_type'])]);
        // Sole global lookups: trusted provider-resource mappings, never a body tenant or Host.
        $resource = $facts['cardId'] ? UserCard::query()->where('provider', 'PHOTONPAY')->whereIn('card_product_id', $products)->where('provider_card_id', $facts['cardId'])->first() : null;
        $column = 'card_id';
        if (! $resource && ! $facts['cardId'] && ! $facts['requestId'] && $facts['cardholderId']) {
            $resource = ProviderCardholder::query()->where('provider', 'PHOTONPAY')->whereIn('card_product_id', $products)->where('provider_cardholder_id', $facts['cardholderId'])->first();
            $column = 'cardholder_id';
        }
        if (! $resource && $facts['requestId'] && Str::isUuid($facts['requestId'])) {
            $resource = CardIssueOrder::query()->where('provider', 'PHOTONPAY')->whereIn('card_product_id', $products)->whereKey($facts['requestId'])->first();
            $column = 'issue_order_id';
        }
        if (! $resource) {
            throw new DomainException('CARD_WEBHOOK_UNMAPPED', 'Notification mapping is not available yet.', 404);
        }
        // If a card is already mapped elsewhere, an order ID must not bypass its account scope.
        if ($facts['cardId'] && UserCard::where('provider', 'PHOTONPAY')->where('provider_card_id', $facts['cardId'])->whereNotIn('card_product_id', $products)->exists()) {
            throw new DomainException('CARD_WEBHOOK_UNMAPPED', 'Notification resource belongs to another account.', 404);
        }
        if ($facts['cardholderId'] && ProviderCardholder::where('provider', 'PHOTONPAY')->where('provider_cardholder_id', $facts['cardholderId'])->whereNotIn('card_product_id', $products)->exists()) {
            throw new DomainException('CARD_WEBHOOK_UNMAPPED', 'Notification holder belongs to another account.', 404);
        }
        $digest = hash('sha256', $account->id."\0".$facts['digest']);
        $event = DB::transaction(function () use ($facts, $resource, $column, $account, $digest): CardProviderEvent {
            // Serialize duplicate deliveries on the trusted tenant row without retaining raw bodies.
            Tenant::query()->whereKey($resource->tenant_id)->lockForUpdate()->firstOrFail();
            $existing = CardProviderEvent::query()->where('tenant_id', $resource->tenant_id)->where($column, $resource->id)->where(function ($q) use ($facts, $digest, $account): void {
                $q->where(function ($q) use ($digest, $account): void {
                    $q->where('event_digest', $digest)->where('card_provider_reference_id', $account->id);
                })
                    ->orWhere(function ($q) use ($facts): void {
                        $q->whereNull('card_provider_reference_id')->where('event_digest', $facts['digest']);
                    });
            })->first();
            if ($existing) {
                return $existing;
            }
            $event = new CardProviderEvent;
            $event->forceFill(['tenant_id' => $resource->tenant_id, 'user_id' => $resource->user_id, $column => $resource->id,
                'event_digest' => $digest, 'card_provider_reference_id' => $account->id, 'category' => $facts['category'], 'event_type' => $facts['event_type'],
                'transaction_id' => $facts['transactionId'], 'request_id' => $facts['requestId'], 'status' => 'PENDING'])->save();

            return $event;
        });
        $logContext = ['tenant_id' => $event->tenant_id, 'event_id' => $event->id, 'resource_id' => $resource->id,
            'event_status' => $event->status, 'has_transaction' => $event->transaction_id !== null];
        PhotonPayLog::write('notification.persisted', $logContext + ['duplicate' => ! $event->wasRecentlyCreated]);
        if ($event->status !== 'PROCESSED') {
            try {
                // One inline synchronization attempt, after committing the verified inbox event.
                // Never require a queue worker or schedule another attempt here.
                PhotonPayLog::write('notification.inline_started', $logContext);
                $this->process->execute($event->tenant_id, $event->id);
            } catch (\Throwable $failure) { // Preserve the inbox and last confirmed state for explicit follow-up.
                PhotonPayLog::write('notification.inline_failed', $logContext + ['failure' => PhotonPayLog::failure($failure)], true);
            }
        }
    }
}
