<?php

namespace App\Domain\Assets;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

abstract class AssetRecord extends Model
{
    use HasUuids;

    protected $guarded = [];
}
