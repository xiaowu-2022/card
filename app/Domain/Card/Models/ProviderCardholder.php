<?php

namespace App\Domain\Card\Models;

use App\Domain\Card\Enums\ProviderCardholderStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class ProviderCardholder extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(function (self $cardholder): void {
            if ($cardholder->isDirty(['tenant_id', 'user_id', 'provider'])) {
                throw new LogicException('Provider cardholder ownership is immutable.');
            }
        });
        self::deleting(fn () => throw new LogicException('Provider cardholder history cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'status' => ProviderCardholderStatus::class,
            'submitted_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
