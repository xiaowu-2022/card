<?php

namespace App\Domain\User\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['password_hash', 'session_version'];

    protected function casts(): array
    {
        return [
            'session_version' => 'integer',
            'password_hash' => 'hashed',
            'status' => UserStatus::class,
            'email_verified_at' => 'immutable_datetime',
            'phone_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function preference(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : strtolower(trim($value));
    }
}
