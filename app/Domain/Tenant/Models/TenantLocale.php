<?php

namespace App\Domain\Tenant\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class TenantLocale extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'is_default' => 'boolean'];
    }
}
