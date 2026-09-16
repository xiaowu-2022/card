<?php

namespace App\Domain\Assets;

use Illuminate\Database\Eloquent\Model;

final class ChainConnection extends Model
{
    protected $table = 'asset_chain_connections';

    protected $guarded = [];

    protected $primaryKey = 'network';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['credential'];

    protected function casts(): array
    {
        return ['credential' => 'encrypted:array', 'enabled' => 'boolean', 'start_height' => 'integer', 'next_height' => 'integer', 'confirmations' => 'integer'];
    }
}
