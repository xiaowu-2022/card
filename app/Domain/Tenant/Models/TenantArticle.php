<?php

namespace App\Domain\Tenant\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class TenantArticle extends Model
{
    use HasUuids;

    protected $guarded = [];
}
