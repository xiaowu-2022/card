<?php

namespace App\Domain\Assets;

final class ExchangePolicy extends AssetRecord
{
    protected $table = 'asset_exchange_policies';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'fee_percent' => 'decimal:8', 'single_limit' => 'decimal:8', 'daily_limit' => 'decimal:8'];
    }
}
