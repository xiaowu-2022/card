<?php

namespace App\Domain\Card\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CardTransaction extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['amount' => 'decimal:8'];
    }
}
