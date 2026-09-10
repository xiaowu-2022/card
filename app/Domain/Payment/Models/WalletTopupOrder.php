<?php

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Enums\WalletTopupStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

final class WalletTopupOrder extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => WalletTopupStatus::class,
            'amount' => 'decimal:8',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'credited_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $order): void {
            if ($order->isDirty(['tenant_id', 'user_id', 'wallet_id', 'request_id', 'request_hash', 'asset_code', 'amount', 'payment_provider'])) {
                throw new LogicException('Top-up order financial identity is immutable.');
            }
            if ($order->getOriginal('status') === WalletTopupStatus::Credited->value
                && $order->isDirty(['status', 'paid_at', 'credited_at', 'ledger_entry_id'])) {
                throw new LogicException('Credited top-up is an immutable financial fact.');
            }
        });
    }

    public function providerTransaction(): HasOne
    {
        return $this->hasOne(PaymentProviderTransaction::class, 'wallet_topup_order_id');
    }
}
