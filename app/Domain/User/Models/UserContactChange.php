<?php

namespace App\Domain\User\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class UserContactChange extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['destination', 'destination_hash', 'session_hash', 'credential_hash', 'code_hash'];

    protected function casts(): array
    {
        return ['destination' => 'encrypted', 'attempt_count' => 'integer', 'delivery_uncertain' => 'boolean',
            'expires_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }
}
