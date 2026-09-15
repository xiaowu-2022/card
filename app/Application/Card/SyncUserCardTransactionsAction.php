<?php

namespace App\Application\Card;

use App\Domain\Card\Models\UserCard;
use App\Domain\CardProvider\Exceptions\ProviderAuthenticationException;
use App\Domain\CardProvider\Exceptions\ProviderRateLimitException;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProvider\Exceptions\ProviderUnavailableException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use App\Domain\CardProvider\ProviderReference;
use App\Infrastructure\Providers\Card\LocalCardSimulation;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;

final readonly class SyncUserCardTransactionsAction
{
    public function __construct(private RecordCardTransactionsAction $record, private UserCardTransactionsQuery $query) {}

    public function execute(string $tenantId, string $userId, string $cardId, int $page): array
    {
        $card = UserCard::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($cardId)->firstOrFail();
        $provider = app(CardProductProviderRouter::class)->forCard($card);
        if (! $provider->available() || $provider->name() !== 'PHOTONPAY' || $card->provider !== 'PHOTONPAY'
            || (ProviderReference::isTest($card->provider_card_id) && ! LocalCardSimulation::allowsCard($card->provider_card_id))) {
            throw new DomainException('CARD_TRANSACTIONS_UNAVAILABLE', 'Card transactions could not be updated. Please try again later.', 503);
        }
        $startedAt = CarbonImmutable::now();
        try {
            // No transaction or row locks around the external read.
            $result = $provider->getTransactionPage($card->provider_card_id, $page, 20);
        } catch (ProviderAuthenticationException|ProviderRateLimitException|ProviderRejectedException|ProviderUnavailableException|ProviderUnknownResultException) {
            throw new DomainException('CARD_TRANSACTIONS_UNAVAILABLE', 'Card transactions could not be updated. Please try again later.', 503);
        }
        $ids = $this->record->execute($card, $result->items, $startedAt);

        return ['page' => $result->page, 'hasMore' => $result->hasMore,
            'items' => $this->query->items($tenantId, $userId, $cardId, $ids)];
    }
}
