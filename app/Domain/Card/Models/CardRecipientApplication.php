<?php

namespace App\Domain\Card\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CardRecipientApplication extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['materials_encrypted', 'request_hash', 'provider_recipient_id'];
}
