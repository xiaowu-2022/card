<?php

namespace App\Domain\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SupportConversation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_sequence' => 'integer'];
    }
}
