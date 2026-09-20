<?php

namespace App\Domain\Assets;

final class MarketSnapshot extends AssetRecord
{
    protected $table = 'asset_market_snapshots';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['usd_prices' => 'array', 'usdt_rates' => 'array', 'observed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
