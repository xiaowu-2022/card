<?php

namespace App\Domain\Wallet\Models;

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Enums\WalletStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class Wallet extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'user_id', 'asset_code', 'status'];

    protected function casts(): array
    {
        return ['status' => WalletStatus::class];
    }

    protected static function booted(): void
    {
        self::updating(function (self $wallet): void {
            if ($wallet->isDirty(['tenant_id', 'user_id', 'asset_code'])) {
                throw new LogicException('Wallet identity is immutable.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(LedgerAccount::class);
    }
}
