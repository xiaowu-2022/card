<?php

namespace App\Domain\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Permission extends Model
{
    use HasUuids;

    protected $guarded = [];
}
