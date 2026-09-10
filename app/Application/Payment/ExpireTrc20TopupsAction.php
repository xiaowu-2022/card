<?php

namespace App\Application\Payment;

use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Domain\Payment\Models\WalletTopupOrder;
use Illuminate\Support\Facades\DB;

final class ExpireTrc20TopupsAction
{
    public function execute(): int
    {
        $ids = WalletTopupOrder::query()->where('payment_rail', 'TRC20_SHARED')
            ->where('status', WalletTopupStatus::Pending->value)->whereNull('matched_tx_hash')
            ->where('expires_at', '<', now())->orderBy('expires_at')->limit(500)->pluck('id');
        $expired = 0;
        foreach ($ids as $id) {
            $expired += DB::transaction(function () use ($id): int {
                $order = WalletTopupOrder::query()->whereKey($id)->lockForUpdate()->first();
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
