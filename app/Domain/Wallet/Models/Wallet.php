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

final class Wallet extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => WalletStatus::class];
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
