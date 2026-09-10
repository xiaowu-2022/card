<?php

namespace App\Domain\Withdrawal\Models;

use App\Domain\Withdrawal\Enums\WithdrawalDestinationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class WithdrawalDestination extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['address_ciphertext', 'address_hash'];

    protected function casts(): array
    {
        return ['status' => WithdrawalDestinationStatus::class];
    }

    protected static function booted(): void
    {
        self::updating(function (self $destination): void {
            if ($destination->isDirty(['tenant_id', 'user_id', 'asset_code', 'network_code', 'address_ciphertext', 'address_hash', 'masked_address'])) {
                throw new LogicException('Withdrawal destination identity is immutable.');
            }
        });
        self::deleting(fn () => throw new LogicException('Withdrawal destinations are retained for financial history.'));
    }
}
