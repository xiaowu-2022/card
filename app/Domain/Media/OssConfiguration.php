<?php

namespace App\Domain\Media;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class OssConfiguration extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected static function booted(): void
    {
        self::updating(function (self $config): void {
            if ($config->isDirty(['region', 'bucket', 'endpoint', 'public_url', 'credentials', 'created_by'])) {
                throw new \LogicException('Storage versions are immutable. Save a new version.');
            }
        });
        self::deleting(fn () => throw new \LogicException('Storage versions must be retained.'));
    }

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'verified_at' => 'immutable_datetime'];
    }
}
