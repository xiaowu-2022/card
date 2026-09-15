<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Support\Errors\DomainException;

final readonly class CardManagementLedger
{
    public function __construct(private LedgerWriter $writer) {}

    public function hold(CardManagementOrder $order): string
    {
        return $this->post($order, 'hold', 'USER_AVAILABLE', 'USER_CARD_FUNDING_HOLD', $order->debit_amount);
    }

    public function settleLoad(CardManagementOrder $order): string
    {
        return $this->post($order, 'settle', 'USER_CARD_FUNDING_HOLD', 'TENANT_CARD_FUNDING_CLEARING', $order->debit_amount);
    }

    public function releaseLoad(CardManagementOrder $order): string
    {
        return $this->post($order, 'release', 'USER_CARD_FUNDING_HOLD', 'USER_AVAILABLE', $order->debit_amount);
    }

    public function settleReturn(CardManagementOrder $order): ?string
    {
        if (Money::of($order->arrival_amount, 'USDT')->isZero()) {
            return null;
        }

        return $this->post($order, 'settle', 'TENANT_CARD_FUNDING_CLEARING', 'USER_AVAILABLE', $order->arrival_amount);
    }

    private function post(CardManagementOrder $order, string $suffix, string $from, string $to, string $amount): string
    {
        // Writer alone owns Ledger account locks; this action runs inside the order transaction.
        $accounts = LedgerAccount::query()->where('tenant_id', $order->tenant_id)->where('asset_code', 'USDT')
            ->whereIn('account_type', [$from, $to])->where(function ($query) use ($order): void {
                $query->where(fn ($query) => $query->where('wallet_id', $order->wallet_id)->where('user_id', $order->user_id))
                    ->orWhere(fn ($query) => $query->whereNull('wallet_id')->whereNull('user_id'));
            })->get()->keyBy(fn ($account) => $account->account_type->value);
        if (! isset($accounts[$from], $accounts[$to])) {
            throw new DomainException('CARD_ACCOUNTS_UNAVAILABLE', 'Card management is currently unavailable.', 409);
        }

        return $this->writer->post(new LedgerPostingPlan($order->tenant_id, 'USDT', "card_management:{$order->id}:{$suffix}",
            'CARD_'.$order->kind.'_'.strtoupper($suffix), 'CARD_MANAGEMENT_ORDER', $order->id, null, [
                new LedgerPostingInstruction($accounts[$from]->id, Money::of('-'.$amount, 'USDT')),
                new LedgerPostingInstruction($accounts[$to]->id, Money::of($amount, 'USDT')),
            ]))->id;
    }
}
