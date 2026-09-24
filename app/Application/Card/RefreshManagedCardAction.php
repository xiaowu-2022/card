<?php

namespace App\Application\Card;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Infrastructure\Providers\Card\LocalCardSimulation;
use App\Support\Errors\DomainException;
use App\Support\Logging\PhotonPayLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class RefreshManagedCardAction
{
    public function __construct(private CardManagementAccess $access, private CardProviderInterface $provider) {}

    public function execute(string $tenantId, string $userId, string $cardId, ?string $transactionId = null): UserCard
    {
        return PhotonPayLog::withContext(['tenant_id' => $tenantId, 'resource_id' => $cardId,
            'has_transaction' => $transactionId !== null, 'transaction_ref' => PhotonPayLog::reference($transactionId)],
            fn () => PhotonPayLog::run('card_refresh', [], fn () => $this->refresh($tenantId, $userId, $cardId, $transactionId)));
    }

    private function refresh(string $tenantId, string $userId, string $cardId, ?string $transactionId): UserCard
    {
        $startedAt = CarbonImmutable::now();
        $snapshot = DB::transaction(function () use ($tenantId, $userId, $cardId): UserCard {
            $card = $this->access->card($tenantId, $userId, $cardId, lock: true, requireActive: false);
            $card->forceFill(['refresh_generation' => $card->refresh_generation + 1])->save();

            return $card;
        });
        // No database locks while talking to PhotonPay. Notifications are wake-ups, not balance deltas.
        if ($transactionId !== null) {
            PhotonPayLog::write('card_refresh.stage', ['stage' => 'transaction_lookup']);
        }
        $transaction = $transactionId === null ? null : app(CardProductProviderRouter::class)->forCard($snapshot)->getTransaction($snapshot->provider_card_id, $transactionId);
        PhotonPayLog::write('card_refresh.stage', ['stage' => 'card_lookup',
            'previous_balance' => $snapshot->provider_balance, 'refresh_generation' => (int) $snapshot->refresh_generation]);
        $result = app(CardProductProviderRouter::class)->forCard($snapshot)->getCard($snapshot->provider_card_id);
        PhotonPayLog::write('card_refresh.stage', ['stage' => 'card_validation', 'provider_balance' => $result->providerBalance]);
        if (($result->isTest && ! LocalCardSimulation::allowsCard($result->providerCardId)) || $result->providerCardId !== $snapshot->provider_card_id || $result->assetCode !== 'USD' || $result->providerBalance === null || $result->last4 !== $snapshot->last4 || $result->formFactor !== $snapshot->form_factor) {
            throw new DomainException('CARD_REFRESH_UNCONFIRMED', 'The latest card information could not be confirmed.', 503);
        }

        PhotonPayLog::write('card_refresh.stage', ['stage' => 'cache_persist']);

        return DB::transaction(function () use ($tenantId, $userId, $cardId, $snapshot, $result, $transaction, $startedAt): UserCard {
            $card = $this->access->card($tenantId, $userId, $cardId, lock: true, requireActive: false);
            if ($card->refresh_generation !== $snapshot->refresh_generation) {
                throw new DomainException('CARD_REFRESH_SUPERSEDED', 'The latest card information is being refreshed.', 409);
            }
            if ($card->provider_status === 'cancelled' && $result->status !== 'cancelled') {
                throw new DomainException('CARD_REFRESH_UNCONFIRMED', 'The latest card information could not be confirmed.', 503);
            }
            $previousBalance = $card->provider_balance;
            $card->forceFill(['provider_balance' => $result->providerBalance, 'provider_status' => $result->status,
                'produce_status' => $result->produceStatus, 'tracking_number' => $result->trackingNumber,
                'provider_balance_synced_at' => now()])->save();
            if ($card->form_factor === 'physical_card' && $result->status === 'normal') {
                $confirmed = DB::table('card_activation_attempts')->where('card_id', $card->id)->whereIn('status', ['PROCESSING', 'UNKNOWN'])->update(['status' => 'SUCCEEDED', 'updated_at' => now()]);
                if ($confirmed) {
                    app(AuditLogger::class)->record($tenantId, 'SYSTEM', null, 'CARD_ACTIVATION_CONFIRMED', 'user_card', $cardId);
                }
            }
            if ($transaction !== null) {
                app(RecordCardTransactionsAction::class)->execute($card, [$transaction], $startedAt);
            }

            // Log only after the outermost transaction commits; never claim a rolled-back update.
            $logContext = ['tenant_id' => $tenantId, 'resource_id' => $cardId,
                'previous_balance' => $previousBalance, 'provider_balance' => $result->providerBalance,
                'stored_balance' => $card->provider_balance, 'refresh_generation' => (int) $card->refresh_generation,
                'synced_epoch' => $card->provider_balance_synced_at->getTimestamp()];
            DB::afterCommit(PhotonPayLog::contextCallback(fn () => PhotonPayLog::write('card_refresh.applied', $logContext)));

            return $card;
        });
    }
}
