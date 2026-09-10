<?php

namespace App\Domain\Withdrawal\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class WithdrawalTransactionAttempt extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_checked_at' => 'immutable_datetime'];
    }
}
