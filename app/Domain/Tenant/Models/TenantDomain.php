<?php

namespace App\Domain\Tenant\Models;

use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Enums\TenantDomainType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TenantDomain extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'domain_type' => TenantDomainType::class,
            'status' => TenantDomainStatus::class,
            'is_primary' => 'boolean',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function setHostnameAttribute(string $value): void
    {
        $this->attributes['hostname'] = strtolower(rtrim(trim($value), '.'));
    }
}
