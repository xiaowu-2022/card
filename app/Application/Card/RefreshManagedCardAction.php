<?php

namespace App\Application\Card;

use App\Domain\Card\Models\UserCard;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Infrastructure\Providers\Card\LocalCardSimulation;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class RefreshManagedCardAction
{
    public function __construct(private CardManagementAccess $access, private CardProviderInterface $provider) {}

    public function execute(string $tenantId, string $userId, string $cardId, ?string $transactionId = null): UserCard
    {
        $startedAt = CarbonImmutable::now();
        $snapshot = DB::transaction(function () use ($tenantId, $userId, $cardId): UserCard {
            $card = $this->access->card($tenantId, $userId, $cardId, lock: true, requireActive: false);
            $card->forceFill(['refresh_generation' => $card->refresh_generation + 1])->save();

            return $card;
        });
        // No database locks while talking to PhotonPay. Notifications are wake-ups, not balance deltas.
        $transaction = $transactionId === null ? null : app(CardProductProviderRouter::class)->forCard($snapshot)->getTransaction($snapshot->provider_card_id, $transactionId);
        $result = app(CardProductProviderRouter::class)->forCard($snapshot)->getCard($snapshot->provider_card_id);
        if (($result->isTest && ! LocalCardSimulation::allowsCard($result->providerCardId)) || $result->providerCardId !== $snapshot->provider_card_id || $result->assetCode !== 'USD' || $result->providerBalance === null || $result->last4 !== $snapshot->last4) {
            throw new DomainException('CARD_REFRESH_UNCONFIRMED', 'The latest card information could not be confirmed.', 503);
        }

        return DB::transaction(function () use ($tenantId, $userId, $cardId, $snapshot, $result, $transaction, $startedAt): UserCard {
            $card = $this->access->card($tenantId, $userId, $cardId, lock: true, requireActive: false);
            if ($card->refresh_generation !== $snapshot->refresh_generation) {
                throw new DomainException('CARD_REFRESH_SUPERSEDED', 'The latest card information is being refreshed.', 409);
            }
            if ($card->provider_status === 'cancelled' && $result->status !== 'cancelled') {
                throw new DomainException('CARD_REFRESH_UNCONFIRMED', 'The latest card information could not be confirmed.', 503);
            }
            $card->forceFill(['provider_balance' => $result->providerBalance, 'provider_status' => $result->status,
                'provider_balance_synced_at' => now()])->save();
            if ($transaction !== null) {
                app(RecordCardTransactionsAction::class)->execute($card, [$transaction], $startedAt);
            }

            return $card;
        });
    }
}
