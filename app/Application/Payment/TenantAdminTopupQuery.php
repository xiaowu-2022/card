<?php

namespace App\Application\Payment;

use App\Domain\Payment\Models\WalletTopupOrder;

final class TenantAdminTopupQuery
{
    /** @return array<string, mixed> */
    public function list(string $tenantId): array
    {
        return ['orders' => WalletTopupOrder::query()->where('tenant_id', $tenantId)->with(['providerTransaction'])
            ->latest()->paginate(25)->through(fn (WalletTopupOrder $order): array => $this->present($order))->toArray()];
    }

    /** @return array<string, mixed> */
    public function detail(string $tenantId, string $orderId): array
    {
        $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)->with('providerTransaction')->firstOrFail();

        return ['order' => $this->present($order, true)];
    }

    /** @return array<string, mixed> */
    private function present(WalletTopupOrder $order, bool $detail = false): array
    {
        $data = [
            'id' => $order->id, 'reference' => strtoupper(substr(str_replace('-', '', $order->id), 0, 12)),
            'userId' => $order->user_id, 'amount' => $order->amount, 'asset' => $order->asset_code,
            'status' => $order->status->value, 'provider' => $order->payment_provider,
            'createdAt' => $order->created_at->toIso8601String(), 'paidAt' => $order->paid_at?->toIso8601String(),
            'creditedAt' => $order->credited_at?->toIso8601String(),
        ];
        if ($detail) {
            $data += [
                'providerStatus' => $order->providerTransaction?->status->value,
                'providerReference' => $order->provider_transaction_id,
                'ledgerEntryId' => $order->ledger_entry_id,
            ];
        }

        return $data;
    }
}
