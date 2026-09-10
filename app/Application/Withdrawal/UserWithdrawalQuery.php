<?php

namespace App\Application\Withdrawal;

use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Withdrawal\Enums\WithdrawalDestinationStatus;
use App\Domain\Withdrawal\Models\WithdrawalDestination;
use App\Domain\Withdrawal\Models\WithdrawalOrder;

final class UserWithdrawalQuery
{
    /** @return array<string,mixed> */
    public function form(string $tenantId, string $userId): array
    {
        User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', 'USDT')->first();
        $available = $wallet ? LedgerAccount::query()->where('tenant_id', $tenantId)->where('wallet_id', $wallet->id)->where('account_type', LedgerAccountType::UserAvailable->value)->value('balance') : '0.00000000';
        $destinations = WithdrawalDestination::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('status', WithdrawalDestinationStatus::Active->value)->latest()->get()->map(fn ($destination): array => [
                'id' => $destination->id, 'maskedAddress' => $destination->masked_address, 'label' => $destination->label,
            ])->all();

        return ['available' => ['amount' => $available ?? '0.00000000', 'asset' => 'USDT'], 'network' => 'TRC20', 'destinations' => $destinations];
    }

    /** @return array<string,mixed> */
    public function order(string $tenantId, string $userId, string $orderId): array
    {
        $order = WithdrawalOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($orderId)->with('destination')->firstOrFail();

        return ['order' => [
            'id' => $order->id, 'amount' => $order->amount, 'asset' => $order->asset_code, 'network' => 'TRC20',
            'maskedAddress' => $order->destination->masked_address, 'status' => $order->status->value,
            'txHash' => $order->status->value === 'SUCCEEDED' ? $order->submitted_tx_hash : null,
            'requestedAt' => $order->requested_at->toIso8601String(), 'reviewReason' => $order->safe_review_reason,
        ]];
    }
}
