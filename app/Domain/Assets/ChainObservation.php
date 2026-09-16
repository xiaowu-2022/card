<?php

namespace App\Domain\Assets;

final class ChainObservation extends AssetRecord
{
    protected $table = 'asset_chain_observations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'block_height' => 'integer'];
    }
}
