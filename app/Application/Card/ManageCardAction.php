<?php

namespace App\Application\Card;

use App\Application\Wallet\WalletEligibilityService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Models\UserCard;
use App\Domain\Card\Services\CardholderMaterials;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\DTOs\CardholderUpdateDTO;
use App\Domain\CardProvider\DTOs\ProviderCardFundsDTO;
use App\Domain\CardProvider\DTOs\ProviderCardQuoteDTO;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\SecurityDeposit\Services\RefundCardPolicy;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ManageCardAction
{
    public function __construct(private CardManagementAccess $access, private CardProviderInterface $provider,
        private CardManagementLedger $ledger, private RefreshManagedCardAction $refresh,
        private CardholderMaterials $materials, private WalletEligibilityService $eligibility, private AuditLogger $audit) {}

    public function quote(string $tenantId, string $userId, string $cardId, string $requestId, string $amount): CardManagementOrder
    {
        $amount = $this->amount($amount);
        RefundCardPolicy::assertAllowed($tenantId, $userId, $cardId);
        $card = $this->access->card($tenantId, $userId, $cardId);
        $existing = $this->existing($tenantId, $userId, $requestId, $this->fingerprint($tenantId, $userId, $cardId, 'LOAD', $amount, []));
        if ($existing) {
            return $existing;
        }
        $balanceReadAt = null;
        if ($card->effectiveBalanceLimit() !== null) {
            $balanceReadAt = now();
            $this->refresh->execute($tenantId, $userId, $cardId);
        }
        [$order, $send] = $this->prepare($tenantId, $userId, $cardId, $requestId, 'LOAD', $amount, balanceReadAt: $balanceReadAt);
        if (! $send) {
            return $order;
        }
        $amount = $order->amount;
        $started = now();
        try {
            // The manual portion never goes to the provider. A zero automatic portion
            // still needs an explicit confirmation and atomic local accounting.
            $quote = Money::of($amount, 'USD')->isZero()
                ? new ProviderCardQuoteDTO($order->provider_request_id, '0.00000000', '0.00000000', '0.00000000')
                : $this->providerFor($order)->quoteCardLoad($order->provider_card_id, $amount, $order->provider_request_id);
            if ($quote->requestId !== $order->provider_request_id || Money::of($amount, 'USD')->compare(Money::of($quote->arrivalAmount, 'USD')) !== 0
                || Money::of($quote->debitAmount, 'USD')->compare(Money::of($quote->arrivalAmount, 'USD')->add(Money::of($quote->feeAmount, 'USD'))) !== 0) {
                throw new \UnexpectedValueException;
            }

            return DB::transaction(function () use ($order, $quote, $started): CardManagementOrder {
                $current = $this->locked($order);
                if ($current->status !== 'QUOTING') {
                    return $current;
                }
                $debit = Money::of($quote->debitAmount, 'USD')->add(Money::of($current->manual_funding_amount, 'USD'))->amount();
                $current->forceFill(['status' => $started->copy()->addSeconds(30)->isFuture() ? 'QUOTED' : 'EXPIRED',
                    'debit_amount' => $debit, 'arrival_amount' => $quote->arrivalAmount,
                    'fee_amount' => $quote->feeAmount, 'quote_expires_at' => $started->copy()->addSeconds(30)])->save();

                return $current;
            });
        } catch (\Throwable) {
            return $this->mark($order, 'FAILED');
        }
    }

    public function confirmLoad(string $tenantId, string $userId, string $cardId, string $orderId): CardManagementOrder
    {
        $snapshot = CardManagementOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('card_id', $cardId)->whereKey($orderId)->firstOrFail();
        if ($snapshot->status === 'QUOTED' && $snapshot->balance_limit_snapshot !== null && Money::of($snapshot->amount, 'USD')->isPositive()) {
            $this->refresh->execute($tenantId, $userId, $cardId);
        }
        [$order, $send] = DB::transaction(function () use ($snapshot): array {
            $card = $this->access->card($snapshot->tenant_id, $snapshot->user_id, $snapshot->card_id, lock: true);
            RefundCardPolicy::assertAllowed($snapshot->tenant_id, $snapshot->user_id, $snapshot->card_id);
            $order = CardManagementOrder::query()->where('tenant_id', $snapshot->tenant_id)->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            if ($order->kind !== 'LOAD') {
                throw new DomainException('CARD_OPERATION_INVALID', 'This card operation is invalid.');
            }
            if ($order->status !== 'QUOTED') {
                return [$order, false];
            }
            if ($order->quote_expires_at === null || $order->quote_expires_at->isPast()) {
                $order->forceFill(['status' => 'EXPIRED'])->save();

                return [$order, false];
            }
            if ($order->balance_limit_snapshot !== null && Money::of($order->amount, 'USD')->isPositive()
                && ($card->provider_balance === null || Money::of($card->availableBalance(), 'USD')->add(Money::of($order->amount, 'USD'))->compare(Money::of($order->balance_limit_snapshot, 'USD')) > 0)) {
                $order->forceFill(['status' => 'EXPIRED'])->save();

                return [$order, false];
            }
            $this->requireLoadEligibility($order->tenant_id, $order->user_id);
            $this->requireStatus($card, 'LOAD');
            app(CardProductProviderRouter::class)->assertNewBusiness($card->product);
            if (Money::of($order->amount, 'USD')->isZero()) {
                $hold = $this->ledger->hold($order);
                $settlement = $this->ledger->settleLoad($order);
                $order->forceFill(['status' => 'SUCCEEDED', 'hold_entry_id' => $hold, 'settlement_entry_id' => $settlement])->save();
                app(CardOverflowLedger::class)->fund($order);
                $this->audit->record($order->tenant_id, 'USER', $order->user_id, 'CARD_MANUAL_FUNDING_ACCEPTED', 'card_management_order', $order->id,
                    after: ['manual_funding_amount' => $order->manual_funding_amount, 'settlement_mode' => 'EXTERNAL_CHANNEL']);

                return [$order, false];
            }
            $order->forceFill(['status' => 'PROCESSING', 'provider_called_at' => now(), 'hold_entry_id' => $this->ledger->hold($order)])->save();

            return [$order, true];
        });
        if (! $send) {
            return $order;
        }
        try {
            return $this->applyFunds($order, $this->providerFor($order)->confirmCardLoad($order->provider_card_id, $order->provider_request_id));
        } catch (ProviderRejectedException) {
            return $this->applyFunds($order, new ProviderCardFundsDTO(ProviderOperationStatus::Failed, $order->provider_card_id, $order->provider_request_id));
        } catch (\Throwable) {
            return $this->mark($order, 'UNKNOWN');
        }
    }

    /** @param array<string,string> $holderFields */
    public function operate(string $tenantId, string $userId, string $cardId, string $requestId, string $kind, ?string $amount = null, #[\SensitiveParameter] array $holderFields = [], ?string $refundId = null): CardManagementOrder
    {
        if (! in_array($kind, ['RETURN', 'FREEZE', 'UNFREEZE', 'CANCEL', 'HOLDER_UPDATE'], true)) {
            throw new DomainException('CARD_OPERATION_INVALID', 'This card operation is invalid.');
        }
        $value = $kind === 'RETURN' ? $this->amount($amount ?? '') : '0.00000000';
        $this->access->card($tenantId, $userId, $cardId);
        $refundId === null ? RefundCardPolicy::assertAllowed($tenantId, $userId, $cardId)
            : RefundCardPolicy::assertWorker($tenantId, $userId, $refundId, $cardId, $kind, $requestId);
        $fingerprint = $this->fingerprint($tenantId, $userId, $cardId, $kind, $value, $holderFields);
        $existing = $this->existing($tenantId, $userId, $requestId, $fingerprint);
        if ($existing) {
            return $existing;
        }
        // Refresh before creating a new intent; ordinary replay never performs a second provider write.
        $this->refresh->execute($tenantId, $userId, $cardId);
        [$order, $send] = $this->prepare($tenantId, $userId, $cardId, $requestId, $kind, $value, $holderFields, $refundId);
        if (! $send) {
            return $order;
        }
        try {
            if ($kind === 'RETURN') {
                return $this->applyFunds($order, $this->providerFor($order)->returnCardFunds($order->provider_card_id, $value, $order->provider_request_id));
            }
            if ($kind === 'HOLDER_UPDATE') {
                $holder = $this->holder($order);
                $this->providerFor($order)->editCardholderFields(new CardholderUpdateDTO($holder->provider_cardholder_id, $holderFields));

                return $this->mark($order, 'SUCCEEDED');
            }
            match ($kind) {
                'FREEZE' => $this->providerFor($order)->freezeCard($order->provider_card_id, $order->provider_request_id),
                'UNFREEZE' => $this->providerFor($order)->unfreezeCard($order->provider_card_id, $order->provider_request_id),
                'CANCEL' => $this->providerFor($order)->cancelCard($order->provider_card_id, $order->provider_request_id),
            };

            return $this->sync($tenantId, $order->id);
        } catch (ProviderRejectedException) {
            if ($kind === 'RETURN') {
                return $this->applyFunds($order, new ProviderCardFundsDTO(ProviderOperationStatus::Failed, $order->provider_card_id, $order->provider_request_id));
            }

            return $this->mark($order, 'FAILED');
        } catch (\Throwable) {
            return $this->mark($order, 'UNKNOWN');
        }
    }

    public function sync(string $tenantId, string $orderId): CardManagementOrder
    {
        $order = CardManagementOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->firstOrFail();
        if ($order->terminal()) {
            return $order;
        }
        if (in_array($order->status, ['QUOTING', 'QUOTED'], true)) {
            if ($order->created_at->lt(now()->subMinute())) {
                return $this->mark($order, 'EXPIRED');
            }

            return $order;
        }
        try {
            if (in_array($order->kind, ['LOAD', 'RETURN', 'CANCEL_RETURN'], true)) {
                return $this->applyFunds($order, $this->providerFor($order)->queryCardFunds($order->provider_card_id, $order->provider_request_id, $order->kind));
            }
            if ($order->kind === 'HOLDER_UPDATE') {
                $fields = json_decode($this->materials->decrypt($order->holder_changes_encrypted), true, flags: JSON_THROW_ON_ERROR);
                $matches = $this->providerFor($order)->cardholderFieldsMatch(new CardholderUpdateDTO($this->holder($order)->provider_cardholder_id, $fields));

                return $this->mark($order, $matches ? 'SUCCEEDED' : 'UNKNOWN');
            }
            $card = $this->refresh->execute($tenantId, $order->user_id, $order->card_id);
            $expected = match ($order->kind) {
                'FREEZE' => 'frozen', 'UNFREEZE' => 'normal', 'CANCEL' => 'cancelled'
            };

            return $this->mark($order, $card->provider_status === $expected ? 'SUCCEEDED' : 'UNKNOWN');
        } catch (\Throwable) {
            return $this->mark($order, 'UNKNOWN');
        }
    }

    /** Notifications may request this only for a provider-verified cancellation-return transaction. */
    public function settleCancellationReturn(string $tenantId, string $userId, string $cardId, string $transactionId): CardManagementOrder
    {
        $card = $this->access->card($tenantId, $userId, $cardId, requireActive: false);
        $result = app(CardProductProviderRouter::class)->forCard($card)->queryCardFunds($card->provider_card_id, $transactionId, 'CANCEL_RETURN');
        if ($result->status !== ProviderOperationStatus::Succeeded || $result->transactionId !== $transactionId) {
            throw new DomainException('CARD_RETURN_UNCONFIRMED', 'The card balance return is awaiting confirmation.', 409);
        }
        $order = DB::transaction(function () use ($tenantId, $userId, $cardId, $transactionId, $result): CardManagementOrder {
            $card = $this->access->card($tenantId, $userId, $cardId, lock: true, requireActive: false);
            $existing = CardManagementOrder::query()->where('tenant_id', $tenantId)->where('card_id', $cardId)->where('provider_transaction_id', $transactionId)->first();
            if ($existing) {
                return $existing;
            }
            if (! CardManagementOrder::query()->where('tenant_id', $tenantId)->where('card_id', $cardId)->where('kind', 'CANCEL')
                ->whereIn('status', ['PROCESSING', 'UNKNOWN', 'SUCCEEDED'])->exists()) {
                throw new DomainException('CARD_RETURN_UNMAPPED', 'The card balance return is awaiting confirmation.', 409);
            }
            $wallet = $this->access->wallet($tenantId, $userId, false);
            $order = new CardManagementOrder;
            $order->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $userId, 'card_id' => $cardId,
                'wallet_id' => $wallet->id, 'request_id' => (string) Str::uuid(), 'request_hash' => hash('sha256', $transactionId),
                'kind' => 'CANCEL_RETURN', 'status' => 'PROCESSING', 'provider_card_id' => $card->provider_card_id,
                'provider_request_id' => $transactionId, 'provider_transaction_id' => $transactionId, 'amount' => $result->debitAmount])->save();

            return $order;
        });

        return $this->applyFunds($order, $result);
    }

    private function applyFunds(CardManagementOrder $snapshot, ProviderCardFundsDTO $result): CardManagementOrder
    {
        $order = DB::transaction(function () use ($snapshot, $result): CardManagementOrder {
            $order = $this->locked($snapshot);
            if ($order->terminal()) {
                return $order;
            }
            if ($result->cardId !== $order->provider_card_id || $result->requestId !== $order->provider_request_id) {
                $order->forceFill(['status' => 'UNKNOWN', 'last_checked_at' => now()])->save();

                return $order;
            }
            if ($result->status === ProviderOperationStatus::Succeeded) {
                $valid = $result->transactionId !== null && $result->debitAmount !== null && $result->arrivalAmount !== null && $result->feeAmount !== null;
                if ($valid) {
                    $valid = Money::of($result->debitAmount, 'USD')->compare(Money::of($result->arrivalAmount, 'USD')->add(Money::of($result->feeAmount, 'USD'))) === 0
                        && Money::of($order->kind === 'LOAD' ? $result->arrivalAmount : $result->debitAmount, 'USD')->compare(Money::of($order->amount, 'USD')) === 0
                        && Money::of($result->debitAmount, 'USD')->isPositive()
                        && ! Money::of($result->arrivalAmount, 'USD')->isNegative() && ! Money::of($result->feeAmount, 'USD')->isNegative();
                    if ($order->kind === 'LOAD') {
                        $valid = $valid && Money::of($order->debit_amount, 'USD')->subtract(Money::of($order->manual_funding_amount, 'USD'))->amount() === $result->debitAmount && $order->arrival_amount === $result->arrivalAmount && $order->fee_amount === $result->feeAmount;
                    }
                }
                if (! $valid) {
                    $order->forceFill(['status' => 'UNKNOWN', 'last_checked_at' => now()])->save();

                    return $order;
                }
                $order->forceFill(['provider_transaction_id' => $result->transactionId, 'debit_amount' => $order->kind === 'LOAD' ? $order->debit_amount : $result->debitAmount,
                    'arrival_amount' => $result->arrivalAmount, 'fee_amount' => $result->feeAmount]);
                $entry = $order->kind === 'LOAD' ? $this->ledger->settleLoad($order) : $this->ledger->settleReturn($order);
                $order->forceFill(['status' => 'SUCCEEDED', 'settlement_entry_id' => $entry, 'last_checked_at' => now()])->save();
                app(CardOverflowLedger::class)->fund($order);
            } elseif ($result->status === ProviderOperationStatus::Failed) {
                $release = $order->kind === 'LOAD' && $order->hold_entry_id ? $this->ledger->releaseLoad($order) : null;
                $order->forceFill(['status' => 'FAILED', 'release_entry_id' => $release, 'last_checked_at' => now()])->save();
            } else {
                $order->forceFill(['status' => 'UNKNOWN', 'last_checked_at' => now()])->save();
            }
            $this->audit->record($order->tenant_id, 'SYSTEM', null, 'CARD_MANAGEMENT_RESULT', 'card_management_order', $order->id,
                after: ['kind' => $order->kind, 'status' => $order->status]);

            return $order;
        });
        if ($order->status === 'SUCCEEDED') {
            try {
                $this->refresh->execute($order->tenant_id, $order->user_id, $order->card_id);
            } catch (\Throwable) { /* Webhook/reconciliation retries without touching settled money. */
            }
        }

        return $order;
    }

    private function prepare(string $tenantId, string $userId, string $cardId, string $requestId, string $kind, string $amount, #[\SensitiveParameter] array $fields = [], ?string $refundId = null, ?CarbonInterface $balanceReadAt = null): array
    {
        if (! Str::isUuid($requestId)) {
            throw new DomainException('CARD_OPERATION_INVALID', 'This card operation is invalid.');
        }
        $fingerprint = $this->fingerprint($tenantId, $userId, $cardId, $kind, $amount, $fields);

        return DB::transaction(function () use ($tenantId, $userId, $cardId, $requestId, $kind, $amount, $fields, $fingerprint, $refundId, $balanceReadAt): array {
            $card = $this->access->card($tenantId, $userId, $cardId, lock: true);
            $refundId === null ? RefundCardPolicy::assertAllowed($tenantId, $userId, $cardId)
                : RefundCardPolicy::assertWorker($tenantId, $userId, $refundId, $cardId, $kind, $requestId);
            $existing = $this->existing($tenantId, $userId, $requestId, $fingerprint);
            if ($existing) {
                return [$existing, false];
            }
            CardManagementOrder::query()->where('tenant_id', $tenantId)->where('card_id', $cardId)->whereIn('status', ['QUOTING', 'QUOTED'])
                ->where('created_at', '<', now()->subMinute())->update(['status' => 'EXPIRED']);
            if (CardManagementOrder::query()->where('tenant_id', $tenantId)->where('card_id', $cardId)
                ->whereIn('status', ['QUOTING', 'QUOTED', 'PROCESSING', 'UNKNOWN'])->where('kind', '!=', 'CANCEL_RETURN')->exists()) {
                throw new DomainException('CARD_OPERATION_OUTSTANDING', 'Wait for the current card operation to finish.', 409);
            }
            $this->requireStatus($card, $kind);
            if ($kind === 'CANCEL' && Money::of($card->overflowBalance(), 'USD')->isPositive()) {
                throw new DomainException('CARD_OVERFLOW_REMAINS', 'The card still has an available balance.', 409);
            }
            if ($kind === 'LOAD') {
                app(CardProductProviderRouter::class)->assertNewBusiness($card->product);
                $this->requireLoadEligibility($tenantId, $userId);
            }
            if ($kind === 'LOAD' && Money::of($amount, 'USD')->compare(Money::of($card->product->minimum_reload, 'USD')) < 0) {
                throw new DomainException('CARD_AMOUNT_INVALID', 'The amount is below this card’s minimum reload.');
            }
            $requestedAmount = $amount;
            $limit = $kind === 'LOAD' ? $card->effectiveBalanceLimit() : null;
            if ($limit !== null) {
                // Reject a racing operation that completed after our external balance read began.
                if ($balanceReadAt === null || $card->provider_balance === null ||
                    CardManagementOrder::query()->where('tenant_id', $tenantId)->where('card_id', $cardId)
                        ->where('updated_at', '>=', $balanceReadAt)->where('status', 'SUCCEEDED')->exists()) {
                    throw new DomainException('CARD_REFRESH_UNCONFIRMED', 'The latest card information could not be confirmed.', 409);
                }
                $room = Money::of($limit, 'USD')->subtract(Money::of($card->availableBalance(), 'USD'));
                // Card loads accept cents; never round remaining capacity upwards.
                $room = Money::of((string) BigDecimal::of($room->amount())->toScale(2, RoundingMode::Down), 'USD');
                if ($room->compare(Money::of($amount, 'USD')) < 0) {
                    $amount = $room->amount();
                }
                if ($kind === 'LOAD' && Money::of($amount, 'USD')->compare(Money::of($card->product->minimum_reload, 'USD')) < 0) {
                    $amount = '0';
                }
            } else {
                if ($kind === 'LOAD' && Money::of($amount, 'USD')->compare(Money::of($card->product->minimum_reload, 'USD')) < 0) {
                    throw new DomainException('CARD_AMOUNT_INVALID', 'The amount is below this card’s minimum reload.');
                }
            }
            if ($kind === 'RETURN' && ($card->provider_balance === null || Money::of($card->provider_balance, 'USD')->compare(Money::of($amount, 'USD')) < 0)) {
                throw new DomainException('CARD_RETURN_AMOUNT_INVALID', 'The return amount exceeds the available card balance.', 409);
            }
            $wallet = $this->access->wallet($tenantId, $userId);
            $id = (string) Str::uuid();
            $order = new CardManagementOrder;
            $order->forceFill(['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'card_id' => $cardId,
                'wallet_id' => $wallet->id, 'request_id' => $requestId, 'request_hash' => $fingerprint,
                'kind' => $kind, 'status' => $kind === 'LOAD' ? 'QUOTING' : 'PROCESSING', 'amount' => $amount,
                'provider_card_id' => $card->provider_card_id, 'provider_request_id' => $id,
                'requested_amount' => $kind === 'LOAD' ? $requestedAmount : null,
                'manual_funding_amount' => $kind === 'LOAD' ? Money::of($requestedAmount, 'USD')->subtract(Money::of($amount, 'USD'))->amount() : '0',
                'overflow_amount' => $kind === 'LOAD' ? Money::of($requestedAmount, 'USD')->subtract(Money::of($amount, 'USD'))->amount() : null,
                'balance_limit_snapshot' => $limit,
                'provider_called_at' => $kind === 'LOAD' ? null : now(),
                'holder_changes_encrypted' => $kind === 'HOLDER_UPDATE' ? $this->materials->encrypt(json_encode($fields, JSON_THROW_ON_ERROR)) : null])->save();
            $this->audit->record($tenantId, 'USER', $userId, 'CARD_MANAGEMENT_REQUESTED', 'card_management_order', $id, after: ['kind' => $kind]);

            return [$order, true];
        });
    }

    private function mark(CardManagementOrder $snapshot, string $status): CardManagementOrder
    {
        return DB::transaction(function () use ($snapshot, $status): CardManagementOrder {
            $order = $this->locked($snapshot);
            if (! $order->terminal()) {
                $order->forceFill(['status' => $status, 'last_checked_at' => now()])->save();
            }

            return $order;
        });
    }

    private function locked(CardManagementOrder $snapshot): CardManagementOrder
    {
        $this->access->card($snapshot->tenant_id, $snapshot->user_id, $snapshot->card_id, lock: true, requireActive: false);

        return CardManagementOrder::query()->where('tenant_id', $snapshot->tenant_id)->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
    }

    private function existing(string $tenantId, string $userId, string $requestId, string $hash): ?CardManagementOrder
    {
        $order = CardManagementOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('request_id', $requestId)->first();
        if ($order && ! hash_equals($order->request_hash, $hash)) {
            throw new DomainException('IDEMPOTENCY_CONFLICT', 'This request was already used with different details.', 409);
        }

        return $order;
    }

    private function holder(CardManagementOrder $order): ProviderCardholder
    {
        $card = UserCard::query()->where('tenant_id', $order->tenant_id)->where('user_id', $order->user_id)->whereKey($order->card_id)->firstOrFail();

        return ProviderCardholder::query()->where('tenant_id', $order->tenant_id)->where('user_id', $order->user_id)->whereKey($card->provider_cardholder_id)->firstOrFail();
    }

    private function requireStatus(UserCard $card, string $kind): void
    {
        $allowed = match ($kind) {
            'LOAD', 'FREEZE' => ['normal'], 'UNFREEZE' => ['frozen'], 'CANCEL' => ['normal', 'frozen', 'expired'], default => ['normal', 'frozen']
        };
        if (! in_array($card->provider_status, $allowed, true)) {
            throw new DomainException('CARD_STATUS_UNAVAILABLE', 'This operation is not available for the current card status.', 409);
        }
    }

    private function requireLoadEligibility(string $tenantId, string $userId): void
    {
        RefundCardPolicy::assertAllowed($tenantId, $userId);
        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        if (! $this->eligibility->forUser($tenant, $user)['canUseCardService']) {
            throw new DomainException('CARD_LOAD_INELIGIBLE', 'Complete identity verification and the required security deposit before reloading.', 403);
        }
    }

    private function amount(string $amount): string
    {
        if (! preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/', $amount)) {
            throw new DomainException('CARD_AMOUNT_INVALID', 'Enter a positive amount with at most two decimal places.');
        }
        $money = Money::of($amount, 'USD');
        if (! $money->isPositive()) {
            throw new DomainException('CARD_AMOUNT_INVALID', 'Enter a positive amount with at most two decimal places.');
        }

        return $money->amount();
    }

    private function fingerprint(string $tenantId, string $userId, string $cardId, string $kind, string $amount, #[\SensitiveParameter] array $fields): string
    {
        ksort($fields);

        return $this->materials->fingerprint($tenantId, $userId, $cardId, json_encode([$kind, $amount, $fields], JSON_THROW_ON_ERROR));
    }

    private function providerFor(CardManagementOrder $order): CardProviderInterface
    {
        $card = UserCard::query()->where('tenant_id', $order->tenant_id)->where('user_id', $order->user_id)->whereKey($order->card_id)->firstOrFail();

        return app(CardProductProviderRouter::class)->forCard($card);
    }
}
