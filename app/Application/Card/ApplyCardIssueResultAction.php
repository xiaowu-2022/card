<?php

namespace App\Application\Card;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Enums\CardIssueStatus;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProvider\DTOs\ProviderOperationDTO;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ApplyCardIssueResultAction
{
    public function __construct(private LedgerWriter $ledger, private AuditLogger $audit) {}

    public function succeed(string $tenantId, string $orderId, ProviderOperationDTO $result): CardIssueOrder
    {
        $card = $result->card;
        if (! $card || $card->assetCode !== 'USD' || $card->last4 === '' || $card->providerCardId === '') {
            return $this->markUnknown($tenantId, $orderId);
        }

        return DB::transaction(function () use ($tenantId, $orderId, $result, $card): CardIssueOrder {
            $order = CardIssueOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->status === CardIssueStatus::Succeeded || $order->status === CardIssueStatus::Failed) {
                return $order;
            }
            if (! hash_equals($order->provider_request_id, $result->providerOperationId)) {
                return $this->markUnknownLocked($order);
            }
            if ($card->providerBalance !== null) {
                try {
                    $providerBalance = Money::of($card->providerBalance, 'USD');
                    $expectedBalance = Money::of($order->initial_load_amount, 'USD');
                } catch (InvalidArgumentException) {
                    return $this->markUnknownLocked($order);
                }
                if ($providerBalance->compare($expectedBalance) !== 0) {
                    return $this->markUnknownLocked($order);
                }
            }
            $accounts = $this->settlementAccounts($order);
            $opening = Money::of($order->opening_fee, 'USDT');
            $initial = Money::of($order->initial_load_amount, 'USDT');
            $feeEntry = null;
            if (! $opening->isZero()) {
                $feeEntry = $this->ledger->post(new LedgerPostingPlan(
                    $tenantId,
                    'USDT',
                    "card_issue:{$order->id}:fee_settle",
                    'CARD_ISSUE_FEE_SETTLE',
                    'CARD_ISSUE_ORDER',
                    $order->id,
                    null,
                    [
                        new LedgerPostingInstruction($accounts[LedgerAccountType::UserCardIssueHold->value]->id, Money::of('-'.$opening->amount(), 'USDT')),
                        new LedgerPostingInstruction($accounts[LedgerAccountType::TenantFeeRevenue->value]->id, $opening),
                    ],
                ));
            }
            $fundingEntry = $this->ledger->post(new LedgerPostingPlan(
                $tenantId,
                'USDT',
                "card_issue:{$order->id}:funding_settle",
                'CARD_INITIAL_LOAD_SETTLE',
                'CARD_ISSUE_ORDER',
                $order->id,
                null,
                [
                    new LedgerPostingInstruction($accounts[LedgerAccountType::UserCardFundingHold->value]->id, Money::of('-'.$initial->amount(), 'USDT')),
                    new LedgerPostingInstruction($accounts[LedgerAccountType::TenantCardFundingClearing->value]->id, $initial),
                ],
            ));
            $expiry = $card->expiryMonth && $card->expiryYear
                ? sprintf('%02d/%02d', $card->expiryMonth, $card->expiryYear % 100)
                : null;
            $userCard = UserCard::query()->where('card_issue_order_id', $order->id)->first();
            if ($userCard && ! hash_equals($userCard->provider_card_id, $card->providerCardId)) {
                throw new DomainException('CARD_ISSUE_PROVIDER_CONFLICT', 'The Provider returned conflicting Card identity.', 409);
            }
            if (! $userCard) {
                $userCard = new UserCard;
                $userCard->forceFill([
                    'tenant_id' => $tenantId,
                    'user_id' => $order->user_id,
                    'card_product_id' => $order->card_product_id,
                    'card_issue_order_id' => $order->id,
                    'provider_cardholder_id' => $order->provider_cardholder_id,
                    'provider' => 'PHOTONPAY',
                    'provider_card_id' => $card->providerCardId,
                    'card_currency' => 'USD',
                    'masked_pan' => $card->maskedPan,
                    'last4' => $card->last4,
                    'expiry' => $expiry,
                    'provider_status' => $card->status,
                    'provider_balance' => $card->providerBalance,
                    'provider_balance_synced_at' => $card->providerBalance === null ? null : now(),
                ])->save();
            }
            $order->forceFill([
                'provider_card_id' => $card->providerCardId,
                'fee_settlement_ledger_entry_id' => $feeEntry?->id,
                'funding_settlement_ledger_entry_id' => $fundingEntry->id,
                'status' => CardIssueStatus::Succeeded,
                'succeeded_at' => now(),
            ])->save();
            $this->audit->record($tenantId, 'SYSTEM', null, 'CARD_ISSUE_SUCCEEDED', 'card_issue_order', $order->id, ['status' => 'UNKNOWN_OR_PROCESSING'], [
                'status' => CardIssueStatus::Succeeded->value,
                'user_card_id' => $userCard->id,
                'fee_settlement_ledger_entry_id' => $feeEntry?->id,
                'funding_settlement_ledger_entry_id' => $fundingEntry->id,
            ]);

            return $order;
        }, 3);
    }

    public function fail(string $tenantId, string $orderId): CardIssueOrder
    {
        return DB::transaction(function () use ($tenantId, $orderId): CardIssueOrder {
            $order = CardIssueOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->status === CardIssueStatus::Failed || $order->status === CardIssueStatus::Succeeded) {
                return $order;
            }
            $accounts = $this->releaseAccounts($order);
            $opening = Money::of($order->opening_fee, 'USDT');
            $initial = Money::of($order->initial_load_amount, 'USDT');
            $feeEntry = null;
            if (! $opening->isZero()) {
                $feeEntry = $this->ledger->post(new LedgerPostingPlan(
                    $tenantId,
                    'USDT',
                    "card_issue:{$order->id}:fee_release",
                    'CARD_ISSUE_FEE_RELEASE',
                    'CARD_ISSUE_ORDER',
                    $order->id,
                    null,
                    [
                        new LedgerPostingInstruction($accounts[LedgerAccountType::UserCardIssueHold->value]->id, Money::of('-'.$opening->amount(), 'USDT')),
                        new LedgerPostingInstruction($accounts[LedgerAccountType::UserAvailable->value]->id, $opening),
                    ],
                ));
            }
            $fundingEntry = $this->ledger->post(new LedgerPostingPlan(
                $tenantId,
                'USDT',
                "card_issue:{$order->id}:funding_release",
                'CARD_INITIAL_LOAD_RELEASE',
                'CARD_ISSUE_ORDER',
                $order->id,
                null,
                [
                    new LedgerPostingInstruction($accounts[LedgerAccountType::UserCardFundingHold->value]->id, Money::of('-'.$initial->amount(), 'USDT')),
                    new LedgerPostingInstruction($accounts[LedgerAccountType::UserAvailable->value]->id, $initial),
                ],
            ));
            $order->forceFill([
                'fee_release_ledger_entry_id' => $feeEntry?->id,
                'funding_release_ledger_entry_id' => $fundingEntry->id,
                'status' => CardIssueStatus::Failed,
                'failed_at' => now(),
            ])->save();
            $this->audit->record($tenantId, 'SYSTEM', null, 'CARD_ISSUE_FAILED', 'card_issue_order', $order->id, ['status' => 'UNKNOWN_OR_PROCESSING'], [
                'status' => CardIssueStatus::Failed->value,
                'fee_release_ledger_entry_id' => $feeEntry?->id,
                'funding_release_ledger_entry_id' => $fundingEntry->id,
            ]);

            return $order;
        }, 3);
    }

    public function markUnknown(string $tenantId, string $orderId): CardIssueOrder
    {
        return DB::transaction(function () use ($tenantId, $orderId): CardIssueOrder {
            $order = CardIssueOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->lockForUpdate()->firstOrFail();

            return $this->markUnknownLocked($order);
        }, 3);
    }

    private function markUnknownLocked(CardIssueOrder $order): CardIssueOrder
    {
        if (! in_array($order->status, [CardIssueStatus::Succeeded, CardIssueStatus::Failed], true)) {
            $order->forceFill(['status' => CardIssueStatus::Unknown])->save();
        }

        return $order;
    }

    /** @return array<string,LedgerAccount> */
    private function settlementAccounts(CardIssueOrder $order): array
    {
        return $this->accounts($order, [
            LedgerAccountType::UserCardIssueHold,
            LedgerAccountType::UserCardFundingHold,
            LedgerAccountType::TenantFeeRevenue,
            LedgerAccountType::TenantCardFundingClearing,
        ]);
    }

    /** @return array<string,LedgerAccount> */
    private function releaseAccounts(CardIssueOrder $order): array
    {
        return $this->accounts($order, [
            LedgerAccountType::UserAvailable,
            LedgerAccountType::UserCardIssueHold,
            LedgerAccountType::UserCardFundingHold,
        ]);
    }

    /** @param list<LedgerAccountType> $types @return array<string,LedgerAccount> */
    private function accounts(CardIssueOrder $order, array $types): array
    {
        $accounts = LedgerAccount::query()->where('tenant_id', $order->tenant_id)->where('asset_code', 'USDT')
            ->whereIn('account_type', array_map(fn (LedgerAccountType $type): string => $type->value, $types))
            ->where(function ($query) use ($order): void {
                $query->where('wallet_id', $order->wallet_id)->orWhereNull('wallet_id');
            })->get()->keyBy(fn (LedgerAccount $account): string => $account->account_type->value);
        foreach ($types as $type) {
            if (! $accounts->has($type->value)) {
                throw new DomainException('CARD_ISSUE_ACCOUNTS_UNAVAILABLE', 'Card issue settlement accounts are unavailable.', 409);
            }
        }

        return $accounts->all();
    }
}
