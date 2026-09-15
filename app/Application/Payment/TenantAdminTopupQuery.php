<?php

namespace App\Application\Payment;

use App\Application\Admin\FinancialOperationQuery;
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
        $order = WalletTopupOrder::query()->where('tenant_id', $tenantId)->whereKey($orderId)
            ->with('providerTransaction.events')->firstOrFail();

        return ['order' => $this->present($order, true)];
    }

    /** @return array<string, mixed> */
    private function present(WalletTopupOrder $order, bool $detail = false): array
    {
        $data = [
            'id' => $order->id, 'reference' => strtoupper(substr(str_replace('-', '', $order->id), 0, 12)),
            'userId' => $order->user_id, 'amount' => $order->amount, 'asset' => $order->asset_code,
            'status' => $order->status->value, 'provider' => $order->payment_provider,
            'manuallyConfirmed' => $order->manual_confirmed_at !== null,
            'manualConfirmedAt' => $order->manual_confirmed_at?->toIso8601String(),
            'requestedAmount' => $order->requested_amount, 'expectedAmount' => $order->expected_amount,
            'identificationIncrement' => $order->identification_increment, 'network' => $order->network_code,
            'createdAt' => $order->created_at->toIso8601String(), 'paidAt' => $order->paid_at?->toIso8601String(),
            'creditedAt' => $order->credited_at?->toIso8601String(),
        ];
        if ($detail) {
            $data += [
                'manualOperations' => app(FinancialOperationQuery::class)->forOrder($order->tenant_id, 'wallet_topup_order', $order->id),
                'providerStatus' => $order->providerTransaction?->status->value,
                'providerReference' => $order->provider_transaction_id,
                'ledgerEntryId' => $order->ledger_entry_id,
                'externalPaymentStatus' => $order->providerTransaction?->status->value ?? 'PENDING',
                'internalCreditStatus' => match ($order->status->value) {
                    'CREDITED' => 'CREDITED',
                    'PAID' => 'CREDIT_PENDING',
                    'REFUNDED' => 'NOT_CREDITED',
                    default => 'NOT_READY',
                },
                'providerException' => $order->providerTransaction?->events
                    ->contains(fn ($event): bool => $event->processing_status->value === 'REQUIRES_REVIEW') ?? false,
                'depositAddress' => $order->deposit_address,
                'expiresAt' => $order->expires_at?->toIso8601String(),
                'matchedTxHash' => $order->matched_tx_hash,
                'matchedTransferIndex' => $order->matched_transfer_index,
                'blockchainDetectedAt' => $order->blockchain_detected_at?->toIso8601String(),
                'blockchainConfirmedAt' => $order->blockchain_confirmed_at?->toIso8601String(),
            ];
        }

        return $data;
    }
}
