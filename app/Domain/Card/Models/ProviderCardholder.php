<?php

namespace App\Domain\Card\Models;

use App\Domain\Card\Enums\ProviderCardholderStatus;
use App\Domain\CardProvider\ProviderReference;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class ProviderCardholder extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['materials_encrypted', 'request_hash'];

    protected static function booted(): void
    {
        self::updating(function (self $cardholder): void {
            if ($cardholder->isDirty(['tenant_id', 'user_id', 'provider', 'request_id', 'card_product_id', 'form_factor'])) {
                throw new LogicException('Provider cardholder ownership is immutable.');
            }
        });
        self::deleting(fn () => throw new LogicException('Provider cardholder history cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'submission_version' => 'integer',
            'status' => ProviderCardholderStatus::class,
            'submitted_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Unknown additions without an external ID must remain visible and blocking. */
    public function scopeWithoutTestReferences(Builder $query): void
    {
        $query->whereRaw('(provider_cardholder_id IS NULL OR BTRIM(provider_cardholder_id) !~* ?)', [ProviderReference::TEST_PATTERN]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
