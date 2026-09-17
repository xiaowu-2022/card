<?php

namespace App\Domain\Assets;

use App\Domain\Ledger\ValueObjects\AssetAmountCast;

final class AssetWithdrawalOrder extends AssetRecord
{
    protected $table = 'asset_withdrawal_orders';

    protected $guarded = [];

    protected $hidden = ['address'];

    protected function casts(): array
    {
        return ['fee_percent' => 'decimal:8', 'amount' => AssetAmountCast::class, 'fee_amount' => AssetAmountCast::class, 'address' => WithdrawalAddressCast::class, 'reviewed_at' => 'immutable_datetime'];
    }
}
