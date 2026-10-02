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
use App\Support\Logging\PhotonPayLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final readonly class SyncUserCardTransactionsAction
{
    public function __construct(private RecordCardTransactionsAction $record, private UserCardTransactionsQuery $query) {}

    public function execute(string $tenantId, string $userId, string $cardId, int $page): array
    {
        $card = UserCard::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($cardId)->firstOrFail();
        $provider = app(CardProductProviderRouter::class)->forCard($card);
        $context = ['request_id' => request()->attributes->get('request_id'), 'tenant_id' => $tenantId, 'user_id' => $userId, 'card_id' => $cardId, 'page' => $page];
        if (! $provider->available() || $provider->name() !== 'PHOTONPAY' || $card->provider !== 'PHOTONPAY'
            || (ProviderReference::isTest($card->provider_card_id) && ! LocalCardSimulation::allowsCard($card->provider_card_id))) {
            Log::warning('Card transaction sync failed', $context + ['failure' => 'provider_or_card_unavailable']);
            throw new DomainException('CARD_TRANSACTIONS_UNAVAILABLE', 'Card transactions could not be updated. Please try again later.', 503);
        }
        $startedAt = CarbonImmutable::now();
        try {
            // No transaction or row locks around the external read.
            $result = $provider->getTransactionPage($card->provider_card_id, $page, 20);
        } catch (ProviderAuthenticationException|ProviderRateLimitException|ProviderRejectedException|ProviderUnavailableException|ProviderUnknownResultException $failure) {
            Log::warning('Card transaction sync failed', $context + ['failure' => PhotonPayLog::failure($failure)]);
            throw new DomainException('CARD_TRANSACTIONS_UNAVAILABLE', 'Card transactions could not be updated. Please try again later.', 503);
        }
        $ids = $this->record->execute($card, $result->items, $startedAt);

        return ['page' => $result->page, 'hasMore' => $result->hasMore,
            'items' => $this->query->items($tenantId, $userId, $cardId, $ids)];
    }
}
