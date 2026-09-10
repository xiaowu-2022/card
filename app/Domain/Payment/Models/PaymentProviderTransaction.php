<?php

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class PaymentProviderTransaction extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => PaymentProviderTransactionStatus::class,
            'amount' => 'decimal:8',
            'initiation_attempted_at' => 'immutable_datetime',
            'initiation_lease_expires_at' => 'immutable_datetime',
            'last_queried_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $transaction): void {
            if ($transaction->isDirty(['tenant_id', 'wallet_topup_order_id', 'provider', 'provider_request_id', 'asset_code', 'amount'])) {
                throw new LogicException('Payment provider transaction identity is immutable.');
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(WalletTopupOrder::class, 'wallet_topup_order_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentProviderEvent::class, 'payment_provider_transaction_id');
    }
}
