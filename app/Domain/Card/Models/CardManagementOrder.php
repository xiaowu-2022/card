<?php

namespace App\Domain\Card\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CardManagementOrder extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['holder_changes_encrypted', 'request_hash'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:8', 'debit_amount' => 'decimal:8', 'arrival_amount' => 'decimal:8', 'fee_amount' => 'decimal:8',
            'quote_expires_at' => 'immutable_datetime', 'provider_called_at' => 'immutable_datetime', 'last_checked_at' => 'immutable_datetime'];
    }

    public function terminal(): bool
    {
        return in_array($this->status, ['SUCCEEDED', 'FAILED', 'EXPIRED'], true);
    }
}
