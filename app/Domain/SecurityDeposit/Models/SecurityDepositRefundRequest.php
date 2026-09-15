<?php

namespace App\Domain\SecurityDeposit\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SecurityDepositRefundRequest extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['card_checks'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:8', 'card_checks' => 'array', 'completed_at' => 'immutable_datetime',
            'refund_wait_days' => 'integer', 'refund_eligible_at' => 'immutable_datetime', 'cancel_requested_at' => 'immutable_datetime'];
    }
}
