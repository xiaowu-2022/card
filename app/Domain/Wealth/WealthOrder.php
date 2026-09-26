<?php

namespace App\Domain\Wealth;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class WealthOrder extends Model
{
    use HasUuids;

    public const MANUAL_RENEW = 'MANUAL_REDEEM_RENEW';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['schedule' => 'array', 'started_at' => 'immutable_datetime', 'matures_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'redeem_before' => 'immutable_datetime'];
    }
}
