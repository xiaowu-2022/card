<?php

namespace App\Domain\Withdrawal\Models;

use App\Domain\Withdrawal\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class WithdrawalOrder extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => WithdrawalStatus::class,
            'amount' => 'decimal:8',
            'requested_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'blockchain_confirmed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $order): void {
            if ($order->isDirty(['tenant_id', 'user_id', 'wallet_id', 'withdrawal_destination_id', 'request_id', 'request_hash', 'asset_code', 'network_code', 'amount', 'requested_at'])) {
                throw new LogicException('Withdrawal financial identity is immutable.');
            }
            if ($order->getOriginal('status') === WithdrawalStatus::Succeeded->value && $order->isDirty()) {
                throw new LogicException('Successful withdrawals are immutable.');
            }
        });
        self::deleting(fn () => throw new LogicException('Withdrawal orders are immutable financial history.'));
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(WithdrawalDestination::class, 'withdrawal_destination_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(WithdrawalTransactionAttempt::class);
    }
}
