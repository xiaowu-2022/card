<?php

namespace App\Domain\CardProduct\Models;

use App\Domain\CardProduct\Enums\CardProductStatus;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CardProduct extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'archived_at' => 'immutable_datetime',
            'status' => CardProductStatus::class,
            'minimum_initial_load' => 'decimal:8',
            'minimum_reload' => 'decimal:8',
            'opening_fee' => 'decimal:8',
        ];
    }

    public function tenantConfigs(): HasMany
    {
        return $this->hasMany(TenantCardProductConfig::class);
    }

    public function cardProviderReference(): BelongsTo
    {
        return $this->belongsTo(CardProviderReference::class);
    }
}
