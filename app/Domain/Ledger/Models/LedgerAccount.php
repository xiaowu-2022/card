<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Enums\LedgerAccountStatus;
use App\Domain\Ledger\Enums\LedgerAccountType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class LedgerAccount extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'account_type' => LedgerAccountType::class,
            'status' => LedgerAccountStatus::class,
            'balance' => 'decimal:8',
        ];
    }
}
