<?php

namespace App\Domain\Promotion\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CommissionAward extends Model
{
    use HasUuids;

    protected $table = 'commission_awards';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:8', 'created_at' => 'immutable_datetime'];
    }
}
