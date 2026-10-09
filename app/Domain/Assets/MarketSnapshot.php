<?php

namespace App\Domain\Assets;

final class MarketSnapshot extends AssetRecord
{
    protected $table = 'asset_market_snapshots';

    protected $guarded = [];

    // Keep the source offset when Eloquent serializes UTC provider timestamps.
    // Without it, PHP/PostgreSQL can reinterpret them in the server timezone.
    protected $dateFormat = 'Y-m-d H:i:sP';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['usd_prices' => 'array', 'usdt_rates' => 'array', 'observed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
