<?php

namespace App\Domain\User\Models;

use App\Domain\User\Enums\RegistrationChallengeStatus;
use App\Domain\User\Enums\RegistrationChannel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class RegistrationChallenge extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'channel' => RegistrationChannel::class,
            'status' => RegistrationChallengeStatus::class,
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
