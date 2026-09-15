<?php

namespace App\Application\Withdrawal;

use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Tenant\Models\TenantBusinessSetting;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Withdrawal\Models\WithdrawalOrder;

final class UserWithdrawalQuery
{
    /** @return array<string,mixed> */
    public function form(string $tenantId, string $userId): array
    {
        User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', 'USDT')->first();
        $available = $wallet ? LedgerAccount::query()->where('tenant_id', $tenantId)->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserAvailable->value)->value('balance') : '0.00000000';

        return ['available' => ['amount' => $available ?? '0.00000000', 'asset' => 'USDT'], 'network' => 'TRC20',
            'fixedFee' => (string) TenantBusinessSetting::query()->where('tenant_id', $tenantId)->soleValue('withdrawal_fixed_fee')];
    }

    /** @return array<string,mixed> */
    public function history(string $tenantId, string $userId, int $page = 1): array
    {
        User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $history = WithdrawalOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->with(['destination' => fn ($query) => $query->where('tenant_id', $tenantId)->where('user_id', $userId)])
            ->orderByDesc('requested_at')->orderByDesc('id')->paginate(10, ['*'], 'page', $page)
            ->through(fn (WithdrawalOrder $order): array => [
                'id' => $order->id, 'amount' => $order->amount, 'asset' => $order->asset_code,
                'feeAmount' => $order->fee_amount, 'receiveAmount' => $order->receive_amount,
                'maskedAddress' => $order->destination->masked_address,
                'state' => match ($order->status->value) {
                    'PENDING' => 'pending', 'APPROVED' => 'processing', 'VERIFYING' => 'confirming',
                    'SUCCEEDED' => 'completed', 'REJECTED' => 'rejected', 'CANCELLED' => 'cancelled',
                },
                'requestedAt' => $order->requested_at->toIso8601String(),
            ]);

        return ['history' => ['data' => $history->items(), 'currentPage' => $history->currentPage(), 'lastPage' => $history->lastPage()]];
    }

    /** @return array<string,mixed> */
    public function order(string $tenantId, string $userId, string $orderId): array
    {
        $order = WithdrawalOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($orderId)->with('destination')->firstOrFail();

        return ['order' => [
            'id' => $order->id, 'amount' => $order->amount, 'asset' => $order->asset_code, 'network' => 'TRC20',
            'feeAmount' => $order->fee_amount, 'receiveAmount' => $order->receive_amount,
            'maskedAddress' => $order->destination->masked_address, 'status' => $order->status->value,
            'txHash' => $order->status->value === 'SUCCEEDED' ? $order->submitted_tx_hash : null,
            'requestedAt' => $order->requested_at->toIso8601String(), 'reviewReason' => $order->safe_review_reason,
        ]];
    }
}
