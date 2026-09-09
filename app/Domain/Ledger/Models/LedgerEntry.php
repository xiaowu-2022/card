<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class LedgerEntry extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['posted_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
        self::deleting(fn () => throw new LogicException('Ledger entries are immutable.'));
    }

    public function postings(): HasMany
    {
        return $this->hasMany(LedgerPosting::class);
    }
}
