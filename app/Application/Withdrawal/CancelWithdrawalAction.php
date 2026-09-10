<?php

namespace App\Application\Withdrawal;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Withdrawal\Enums\WithdrawalStatus;
use App\Domain\Withdrawal\Models\WithdrawalOrder;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class CancelWithdrawalAction
{
    public function __construct(private LedgerWriter $ledger, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $userId, string $orderId, ?string $requestId = null): WithdrawalOrder
    {
        return DB::transaction(function () use ($tenantId, $userId, $orderId, $requestId): WithdrawalOrder {
            $order = WithdrawalOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->status === WithdrawalStatus::Cancelled) {
                return $order;
            }
            if ($order->status !== WithdrawalStatus::Pending) {
                throw new DomainException('WITHDRAWAL_CANNOT_CANCEL', 'Only a pending withdrawal can be cancelled.', 409);
            }
            $accounts = $this->accounts($order);
            $money = Money::of($order->amount, 'USDT');
            $entry = $this->ledger->post(new LedgerPostingPlan(
                $tenantId, 'USDT', "withdrawal:{$order->id}:release", 'WITHDRAWAL_RELEASE', 'WITHDRAWAL_ORDER', $order->id, null,
                [new LedgerPostingInstruction($accounts['hold']->id, Money::of('-'.$money->amount(), 'USDT')), new LedgerPostingInstruction($accounts['available']->id, $money)],
            ));
            $order->update(['status' => WithdrawalStatus::Cancelled, 'release_ledger_entry_id' => $entry->id]);
            $this->audit->record($tenantId, 'USER', $userId, 'WITHDRAWAL_CANCELLED', 'withdrawal_order', $order->id, null, ['ledger_entry_id' => $entry->id], $requestId);

            return $order;
        }, 3);
    }

    /** @return array{available:LedgerAccount,hold:LedgerAccount} */
    private function accounts(WithdrawalOrder $order): array
    {
        $accounts = LedgerAccount::query()->where('tenant_id', $order->tenant_id)->where('wallet_id', $order->wallet_id)
            ->whereIn('account_type', [LedgerAccountType::UserAvailable->value, LedgerAccountType::UserWithdrawalHold->value])->get()->keyBy(fn ($account) => $account->account_type->value);
        if (! $accounts->has(LedgerAccountType::UserAvailable->value) || ! $accounts->has(LedgerAccountType::UserWithdrawalHold->value)) {
            throw new DomainException('WITHDRAWAL_ACCOUNTS_UNAVAILABLE', 'Withdrawal accounts are unavailable.', 409);
        }

        return ['available' => $accounts[LedgerAccountType::UserAvailable->value], 'hold' => $accounts[LedgerAccountType::UserWithdrawalHold->value]];
    }
}
