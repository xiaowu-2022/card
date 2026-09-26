<?php

namespace App\Domain\Wallet\Models;

use App\Domain\Ledger\ValueObjects\AssetAmountCast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class WalletTransfer extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'amount' => AssetAmountCast::class];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Wallet transfer receipts are immutable.'));
        self::deleting(fn () => throw new LogicException('Wallet transfer receipts are immutable.'));
    }
}
