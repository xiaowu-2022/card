<?php

namespace App\Domain\Card\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CardProviderEvent extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['event_digest'];
}
