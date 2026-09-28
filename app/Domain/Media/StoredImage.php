<?php

namespace App\Domain\Media;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class StoredImage extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['source_key', 'object_key'];

    protected function casts(): array
    {
        return ['cleanup_after' => 'immutable_datetime', 'size' => 'integer'];
    }
}
