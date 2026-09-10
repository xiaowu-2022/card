<?php

namespace App\Domain\Payment\Models;

use App\Domain\Payment\Enums\PaymentEventProcessingStatus;
use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class PaymentProviderEvent extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'processing_status' => PaymentEventProcessingStatus::class,
            'normalized_status' => PaymentProviderTransactionStatus::class,
            'amount' => 'decimal:8',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $event): void {
            if ($event->isDirty(['tenant_id', 'provider', 'provider_event_key', 'event_type', 'provider_transaction_id', 'provider_request_id', 'normalized_status', 'asset_code', 'amount', 'payload_digest', 'received_at'])) {
                throw new LogicException('Payment provider event facts are immutable.');
            }
        });
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderTransaction::class, 'payment_provider_transaction_id');
    }

    public function isRefundLike(): bool
    {
        return str_contains(strtoupper($this->event_type), 'REFUND') || str_contains(strtoupper($this->event_type), 'CHARGEBACK');
    }
}
