<?php

namespace App\Application\Payment;

use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Domain\Payment\Models\WalletTopupOrder;
use Illuminate\Support\Facades\DB;

final class ExpireTrc20TopupsAction
{
    public function execute(?\DateTimeImmutable $through = null, ?\DateTimeImmutable $notBefore = null, ?string $destination = null): int
    {
        $ids = WalletTopupOrder::query()->where('payment_rail', 'TRC20_SHARED')
            ->where('status', WalletTopupStatus::Pending->value)->whereNull('matched_tx_hash')
            ->where('expires_at', '<', ($through ?? now())->format('Y-m-d H:i:s.uP'))
            ->when($notBefore, fn ($query) => $query->where('created_at', '>=', $notBefore->format('Y-m-d H:i:s.uP')))
            ->when($destination, fn ($query) => $query->where('deposit_address', $destination))
            ->orderBy('expires_at')->limit(500)->get(['id', 'tenant_id']);
        $expired = 0;
        foreach ($ids as $snapshot) {
            $expired += DB::transaction(function () use ($snapshot): int {
                $order = WalletTopupOrder::query()->where('tenant_id', $snapshot->tenant_id)->whereKey($snapshot->id)->lockForUpdate()->first();
                if (! $order || $order->status !== WalletTopupStatus::Pending || $order->matched_tx_hash !== null || ! $order->expires_at?->isPast()) {
                    return 0;
                }
                $order->status = WalletTopupStatus::Expired;
                $order->save();
                PaymentProviderTransaction::query()->where('tenant_id', $order->tenant_id)
                    ->where('wallet_topup_order_id', $order->id)->update([
                        'status' => PaymentProviderTransactionStatus::Failed->value,
                        'updated_at' => now(),
                    ]);

                return 1;
            }, 3);
        }

        return $expired;
    }
}
