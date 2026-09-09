<?php

namespace App\Domain\Tenant\Models;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Tenant\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Tenant extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'activated_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function branding(): HasOne
    {
        return $this->hasOne(TenantBranding::class);
    }

    public function businessSettings(): HasOne
    {
        return $this->hasOne(TenantBusinessSetting::class);
    }

    public function kycSettings(): HasOne
    {
        return $this->hasOne(TenantKycSetting::class);
    }

    public function locales(): HasMany
    {
        return $this->hasMany(TenantLocale::class);
    }

    public function adminMemberships(): HasMany
    {
        return $this->hasMany(AdminMembership::class, 'scope_id')
            ->where('scope_type', ScopeType::Tenant);
    }
}
