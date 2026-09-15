<?php

namespace App\Domain\Notification\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class TenantEmailSetting extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['smtp_token'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'smtp_token' => 'encrypted', 'daily_recipient_limit' => 'integer'];
    }

    public function tokenConfigured(): bool
    {
        return filled($this->getRawOriginal('smtp_token'));
    }

    public function available(): bool
    {
        return $this->enabled && $this->tokenConfigured() && filled($this->from_address) && filled($this->from_name);
    }
}
