<?php

namespace App\Domain\Notification\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PlatformSmsProfile extends Model
{
    use HasUuids;

    protected $table = 'platform_sms_profiles';

    protected $guarded = [];

    protected $hidden = ['access_key_id', 'access_key_secret'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'access_key_id' => 'encrypted',
            'access_key_secret' => 'encrypted',
            'resend_interval_seconds' => 'integer',
            'code_ttl_seconds' => 'integer',
        ];
    }

    public function credentialsConfigured(): bool
    {
        return filled($this->getRawOriginal('access_key_id')) && filled($this->getRawOriginal('access_key_secret'));
    }

    public function available(): bool
    {
        return $this->enabled && $this->credentialsConfigured() && filled($this->sign_name) && filled($this->verification_template_code);
    }
}
