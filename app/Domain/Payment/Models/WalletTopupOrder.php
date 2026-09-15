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
            'requested_amount' => 'decimal:8',
            'expected_amount' => 'decimal:8',
            'identification_increment' => 'decimal:8',
            'matched_transfer_index' => 'integer',
            'expires_at' => 'immutable_datetime',
            'blockchain_detected_at' => 'immutable_datetime',
            'blockchain_confirmed_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'credited_at' => 'immutable_datetime',
            'manual_confirmed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $order): void {
            if ($order->getOriginal('manual_confirmed_at') !== null && $order->isDirty(['manual_confirmed_at', 'manual_confirmed_by', 'manual_confirmation_request_id'])) {
                throw new LogicException('Manual top-up confirmation is immutable.');
            }
            if ($order->isDirty(['tenant_id', 'user_id', 'wallet_id', 'request_id', 'request_hash', 'asset_code', 'amount', 'payment_provider', 'payment_rail', 'requested_amount', 'expected_amount', 'identification_increment', 'network_code', 'deposit_address', 'token_contract', 'expires_at'])) {
                throw new LogicException('Top-up order financial identity is immutable.');
            }
            if ($order->getOriginal('matched_tx_hash') !== null && $order->isDirty(['matched_tx_hash', 'matched_transfer_index', 'blockchain_detected_at'])) {
                throw new LogicException('Top-up blockchain match is immutable.');
            }
            if ($order->getOriginal('blockchain_confirmed_at') !== null && $order->isDirty('blockchain_confirmed_at')) {
                throw new LogicException('Top-up blockchain confirmation is immutable.');
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
