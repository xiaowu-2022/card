<?php

namespace App\Domain\Notification\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class TenantEmailTestRequest extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['recipient_hash'];
}
