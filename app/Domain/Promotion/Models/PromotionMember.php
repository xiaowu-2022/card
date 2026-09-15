<?php

namespace App\Domain\Promotion\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PromotionMember extends Model
{
    use HasUuids;

    protected $guarded = [];
}
