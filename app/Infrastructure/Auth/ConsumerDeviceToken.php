<?php

namespace App\Infrastructure\Auth;

use Laravel\Sanctum\PersonalAccessToken;

final class ConsumerDeviceToken extends PersonalAccessToken
{
    protected $table = 'consumer_device_tokens';

    protected $guarded = [];

    protected $fillable = ['tenant_id', 'tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'session_version', 'expires_at'];

    protected function casts(): array
    {
        return [...parent::casts(), 'session_version' => 'integer'];
    }
}
