<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Enums\LedgerAccountStatus;
use App\Domain\Ledger\Enums\LedgerAccountType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class LedgerAccount extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'account_type' => LedgerAccountType::class,
            'status' => LedgerAccountStatus::class,
            'balance' => 'decimal:8',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $account): void {
            if ($account->isDirty(['tenant_id', 'wallet_id', 'user_id', 'account_type', 'asset_code'])) {
                throw new LogicException('Ledger account identity is immutable.');
            }
        });
    }
}
