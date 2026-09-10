<?php

namespace App\Application\Card;

use App\Domain\Card\Enums\CardIssueStatus;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Support\Errors\DomainException;
use Throwable;

final readonly class SyncCardIssueAction
{
    public function __construct(private CardProviderInterface $provider, private ApplyCardIssueResultAction $results) {}

    public function execute(string $tenantId, string $orderId, ?string $userId = null): CardIssueOrder
    {
        $query = CardIssueOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId);
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        $order = $query->firstOrFail();
        if (in_array($order->status, [CardIssueStatus::Succeeded, CardIssueStatus::Failed], true)) {
            return $order;
        }
        if (! $this->provider->available() || $order->provider !== $this->provider->name()) {
            throw new DomainException('CARD_PROVIDER_UNAVAILABLE', 'Card status cannot be refreshed right now.', 503);
        }
        try {
            $result = $this->provider->queryOperation($order->provider_request_id);
        } catch (Throwable) {
            return $this->results->markUnknown($tenantId, $orderId);
        }
        if (! hash_equals($order->provider_request_id, $result->providerOperationId)) {
            return $this->results->markUnknown($tenantId, $orderId);
        }

        return match ($result->status) {
            ProviderOperationStatus::Succeeded => $this->results->succeed($tenantId, $orderId, $result),
            ProviderOperationStatus::Failed => $this->results->fail($tenantId, $orderId),
            ProviderOperationStatus::Unknown => $this->results->markUnknown($tenantId, $orderId),
            default => $order->fresh(),
        };
    }
}
