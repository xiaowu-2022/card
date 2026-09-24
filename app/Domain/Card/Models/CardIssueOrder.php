<?php

namespace App\Domain\Card\Models;

use App\Domain\Card\Enums\CardIssueStatus;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class CardIssueOrder extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['recipient_snapshot_encrypted'];

    protected static function booted(): void
    {
        self::updating(function (self $order): void {
            if ($order->isDirty([
                'tenant_id', 'user_id', 'wallet_id', 'card_product_id', 'tenant_card_product_config_id',
                'provider_cardholder_id', 'cardholder_request_id', 'request_id', 'request_hash', 'opening_fee', 'minimum_initial_load',
                'form_factor', 'recipient_application_id', 'provider_recipient_id', 'recipient_snapshot_encrypted',
                'initial_load_amount', 'wallet_asset', 'card_currency', 'provider', 'provider_product_ref', 'provider_request_id',
            ])) {
                throw new LogicException('Card issue financial identity is immutable.');
            }
            if (in_array($order->getRawOriginal('status'), [CardIssueStatus::Succeeded->value, CardIssueStatus::Failed->value], true)
                && $order->isDirty(['status', 'provider_card_id', 'fee_settlement_ledger_entry_id', 'funding_settlement_ledger_entry_id', 'fee_release_ledger_entry_id', 'funding_release_ledger_entry_id'])) {
                throw new LogicException('Terminal Card issue results are immutable.');
            }
        });
        self::deleting(fn () => throw new LogicException('Card issue orders cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'status' => CardIssueStatus::class,
            'opening_fee' => 'decimal:8',
            'minimum_initial_load' => 'decimal:8',
            'initial_load_amount' => 'decimal:8',
            'requested_at' => 'immutable_datetime',
            'processing_at' => 'immutable_datetime',
            'succeeded_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CardProduct::class, 'card_product_id');
    }

    public function tenantConfig(): BelongsTo
    {
        return $this->belongsTo(TenantCardProductConfig::class, 'tenant_card_product_config_id');
    }

    public function cardholder(): BelongsTo
    {
        return $this->belongsTo(ProviderCardholder::class, 'provider_cardholder_id');
    }
}
