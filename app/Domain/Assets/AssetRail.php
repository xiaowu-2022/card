<?php

namespace App\Domain\Assets;

use Illuminate\Database\Eloquent\Model;

final class AssetRail extends Model
{
    protected $table = 'asset_rails';

    protected $guarded = [];

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
