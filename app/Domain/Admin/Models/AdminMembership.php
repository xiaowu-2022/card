<?php

namespace App\Domain\Admin\Models;

use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AdminMembership extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scope_type' => ScopeType::class, 'status' => MembershipStatus::class];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
