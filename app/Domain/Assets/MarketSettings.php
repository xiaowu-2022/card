<?php

namespace App\Domain\Assets;

use Illuminate\Database\Eloquent\Model;

final class MarketSettings extends Model
{
    protected $table = 'asset_market_settings';

    protected $guarded = [];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'enabled' => 'boolean'];
    }
}
