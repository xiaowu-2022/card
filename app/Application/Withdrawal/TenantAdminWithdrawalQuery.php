<?php

namespace App\Application\Withdrawal;

use App\Domain\Withdrawal\Models\WithdrawalOrder;

final class TenantAdminWithdrawalQuery
{
    /** @return array<string,mixed> */
    public function list(string $tenantId): array
    {
        return ['orders' => WithdrawalOrder::query()->where('tenant_id', $tenantId)->with('destination')->latest('requested_at')->paginate(25)
            ->through(fn (WithdrawalOrder $order): array => $this->present($order))->toArray()];
    }

    /** @return array<string,mixed> */
    public function detail(string $tenantId, string $orderId): array
    {
        $order = WithdrawalOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->with(['destination', 'attempts'])->firstOrFail();

        return ['order' => $this->present($order, true)];
    }

    /** @return array<string,mixed> */
    private function present(WithdrawalOrder $order, bool $detail = false): array
    {
        $data = [
            'id' => $order->id, 'userId' => $order->user_id, 'amount' => $order->amount, 'asset' => $order->asset_code,
            'network' => 'TRC20', 'maskedAddress' => $order->destination->masked_address, 'status' => $order->status->value,
            'requestedAt' => $order->requested_at->toIso8601String(),
        ];
        if ($detail) {
            $data += [
                'reviewedAt' => $order->reviewed_at?->toIso8601String(), 'reviewReason' => $order->safe_review_reason,
                'txHash' => $order->submitted_tx_hash, 'confirmedAt' => $order->blockchain_confirmed_at?->toIso8601String(),
                'lastVerificationFailure' => $order->attempts->sortByDesc('created_at')->first()?->safe_failure_code,
            ];
        }

        return $data;
    }
}
