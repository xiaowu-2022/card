<?php

namespace App\Domain\Media;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class StoredImage extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['source_key', 'object_key', 'backup_key', 'backup_sha256'];

    protected function casts(): array
    {
        return ['oss_pending' => 'boolean', 'cleanup_after' => 'immutable_datetime', 'size' => 'integer'];
    }
}
