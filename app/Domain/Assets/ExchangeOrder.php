<?php

namespace App\Domain\Assets;

use App\Domain\Ledger\ValueObjects\AssetAmountCast;

final class ExchangeOrder extends AssetRecord
{
    protected $table = 'asset_exchange_orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => AssetAmountCast::class, 'gross_amount' => 'decimal:8', 'fee_amount' => 'decimal:8', 'receive_amount' => 'decimal:8', 'rate' => 'decimal:18', 'fee_percent' => 'decimal:8', 'expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
