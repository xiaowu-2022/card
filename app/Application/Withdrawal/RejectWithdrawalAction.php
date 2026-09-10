<?php

namespace App\Application\Withdrawal;

use App\Domain\Admin\Models\AdminUser;
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

final readonly class RejectWithdrawalAction
{
    public function __construct(private LedgerWriter $ledger, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $orderId, AdminUser $actor, string $reason, ?string $requestId = null): WithdrawalOrder
    {
        $reason = trim(strip_tags($reason));
        if ($reason === '' || mb_strlen($reason) > 240) {
            throw new DomainException('WITHDRAWAL_REVIEW_REASON_INVALID', 'Provide a safe review reason of at most 240 characters.');
        }

        return DB::transaction(function () use ($tenantId, $orderId, $actor, $reason, $requestId): WithdrawalOrder {
            $order = WithdrawalOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->status === WithdrawalStatus::Rejected) {
                return $order;
            }
            if (! in_array($order->status, [WithdrawalStatus::Pending, WithdrawalStatus::Approved], true) || $order->submitted_tx_hash !== null) {
                throw new DomainException('WITHDRAWAL_CANNOT_REJECT', 'This withdrawal can no longer be rejected.', 409);
            }
            $accounts = LedgerAccount::query()->where('tenant_id', $tenantId)->where('wallet_id', $order->wallet_id)
                ->whereIn('account_type', [LedgerAccountType::UserAvailable->value, LedgerAccountType::UserWithdrawalHold->value])->get()->keyBy(fn ($account) => $account->account_type->value);
            $available = $accounts->get(LedgerAccountType::UserAvailable->value);
            $hold = $accounts->get(LedgerAccountType::UserWithdrawalHold->value);
            if (! $available || ! $hold) {
                throw new DomainException('WITHDRAWAL_ACCOUNTS_UNAVAILABLE', 'Withdrawal accounts are unavailable.', 409);
            }
            $money = Money::of($order->amount, 'USDT');
            $entry = $this->ledger->post(new LedgerPostingPlan(
                $tenantId, 'USDT', "withdrawal:{$order->id}:release", 'WITHDRAWAL_RELEASE', 'WITHDRAWAL_ORDER', $order->id, null,
                [new LedgerPostingInstruction($hold->id, Money::of('-'.$money->amount(), 'USDT')), new LedgerPostingInstruction($available->id, $money)],
            ));
            $order->update([
                'status' => WithdrawalStatus::Rejected, 'release_ledger_entry_id' => $entry->id, 'reviewed_at' => now(),
                'reviewed_by_admin_user_id' => $actor->id, 'safe_review_reason' => $reason,
            ]);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'WITHDRAWAL_REJECTED', 'withdrawal_order', $order->id, null, ['reason' => $reason, 'ledger_entry_id' => $entry->id], $requestId);

            return $order;
        }, 3);
    }
}
