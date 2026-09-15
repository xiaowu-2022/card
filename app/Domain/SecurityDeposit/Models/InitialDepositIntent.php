<?php

namespace App\Domain\SecurityDeposit\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class InitialDepositIntent extends Model
{
    use HasUuids;

    protected $guarded = [];
}
