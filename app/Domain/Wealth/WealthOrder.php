<?php

namespace App\Domain\Wealth;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class WealthOrder extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['schedule' => 'array', 'started_at' => 'immutable_datetime', 'matures_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }
}
