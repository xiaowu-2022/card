<?php

namespace App\Domain\Promotion\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PromotionLevel extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reward_amount' => 'decimal:8', 'rank' => 'integer', 'revision' => 'integer'];
    }
}
