<?php

namespace App\Domain\Promotion\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CommissionTransfer extends Model
{
    use HasUuids;

    protected $table = 'commission_transfers';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:8', 'created_at' => 'immutable_datetime'];
    }
}
