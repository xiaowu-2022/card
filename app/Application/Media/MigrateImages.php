<?php

namespace App\Application\Media;

use App\Domain\Media\OssConfiguration;
use App\Domain\Media\StoredImage;
use App\Infrastructure\Storage\OssImages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MigrateImages
{
    public function __construct(private ImageStorage $images, private OssImages $oss) {}

    public function migrate(array $ref): StoredImage
    {
        $config = $this->images->active();
        if (! $config?->verified_at) {
            throw new \RuntimeException('Verified active OSS required');
        }
        $record = $this->images->record($ref['disk'], $ref['key']);
        if ($record && $record->tenant_id !== $ref['tenant']) {
            throw new \LogicException('Image ownership mismatch');
        }
        if ($record?->configuration_id) {
            return $record;
        }
        $contents = $this->images->read($ref['disk'], $ref['key'], $ref['codec']);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon'], true)) {
            throw new \RuntimeException('Invalid source image');
        }
        $hash = hash('sha256', $contents);
        $record ??= StoredImage::create(['tenant_id' => $ref['tenant'], 'source_disk' => $ref['disk'], 'source_key' => $ref['key'], 'purpose' => $ref['purpose'], 'business_reference' => $ref['reference'],
            'object_key' => $ref['key'], 'codec' => $ref['codec'], 'mime' => $mime, 'size' => strlen($contents), 'sha256' => $hash, 'state' => 'ready']);
        if (! $record->migration_configuration_id) {
            $record->update(['migration_configuration_id' => $config->id, 'migration_object_key' => 'images/'.$ref['tenant'].'/'.Str::uuid()]);
        }
        $target = OssConfiguration::findOrFail($record->migration_configuration_id);
        try {
            $this->oss->put($target, $record->migration_object_key, $contents, $mime);
            if (! hash_equals($hash, hash('sha256', $this->oss->get($target, $record->migration_object_key)))) {
                throw new \RuntimeException('Checksum mismatch');
            }
            // Detect local changes during copying, before publishing the storage mapping.
            if (! hash_equals($hash, hash('sha256', $this->images->read($ref['disk'], $ref['key'], $ref['codec'])))) {
                throw new \RuntimeException('Source changed');
            }
            DB::transaction(function () use ($record, $target, $hash, $contents, $mime) {
                $locked = StoredImage::whereKey($record->id)->lockForUpdate()->firstOrFail();
                if ($locked->configuration_id || $locked->state !== 'ready') {
                    throw new \RuntimeException('Image state changed');
                }
                $locked->update(['configuration_id' => $target->id, 'object_key' => $locked->migration_object_key, 'codec' => 'plain', 'sha256' => $hash, 'size' => strlen($contents), 'mime' => $mime, 'last_error' => null, 'attempts' => $locked->attempts + 1]);
            });
        } catch (\Throwable) {
            $record->update(['last_error' => 'MIGRATION_FAILED', 'attempts' => $record->attempts + 1]);
            throw new \RuntimeException('Image migration failed; original storage retained.');
        }

        return $record->fresh();
    }
}
