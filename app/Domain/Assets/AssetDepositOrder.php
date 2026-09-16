<?php

namespace App\Domain\Assets;

use App\Domain\Ledger\ValueObjects\AssetAmountCast;

final class AssetDepositOrder extends AssetRecord
{
    protected $table = 'asset_deposit_orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => AssetAmountCast::class, 'requested_amount' => AssetAmountCast::class, 'expires_at' => 'immutable_datetime', 'manual_confirmed_at' => 'immutable_datetime'];
    }
}
