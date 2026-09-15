<?php

namespace App\Domain\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SupportMessage extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $hidden = ['support_message', 'image_object_key', 'image_hash', 'sender_user_id', 'sender_admin_id', 'request_id'];

    protected function casts(): array
    {
        return ['support_message' => 'encrypted', 'image_object_key' => 'encrypted', 'sequence' => 'integer'];
    }
}
