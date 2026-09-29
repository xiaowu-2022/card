<?php

namespace App\Console\Commands;

use App\Application\Media\ImageReferences;
use App\Application\Media\ImageReplicas;
use App\Application\Media\ImageStorage;
use App\Application\Media\ServerImages;
use App\Domain\Media\OssConfiguration;
use App\Domain\Media\StoredImage;
use App\Infrastructure\Storage\OssImages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

final class ReplicateImages extends Command
{
    protected $signature = 'images:replicate {--limit=20} {--backfill : Also back up existing ready OSS originals}';

    protected $description = 'Repair image copies without replaying business submissions, OCR or provider calls';

    public function handle(ImageReplicas $replicas, ImageStorage $images, OssImages $oss): int
    {
        if (ServerImages::enabled()) {
            $this->info('Server storage active; OSS replication disabled.');

            return self::SUCCESS;
        }
        $rows = StoredImage::where('state', 'ready')->whereNotNull('configuration_id')
            ->where(function ($q) {
                $q->where('oss_pending', true);
                if ($this->option('backfill')) {
                    $q->orWhereNull('backup_key');
                }
            })->orderBy('updated_at')->limit(max(1, min(1000, (int) $this->option('limit'))))->get();
        $failed = 0;
        foreach ($rows as $image) {
            $lock = Cache::lock('image-replica:'.$image->id, 300);
            if (! $lock->get()) {
                continue;
            }
            try {
                $image->refresh();
                if ($image->state !== 'ready') {
                    continue;
                }
                $config = OssConfiguration::findOrFail($image->configuration_id);
                if ($image->oss_pending) {
                    $bytes = $replicas->read($image);
                    $oss->put($config, $image->object_key, $bytes, $image->mime);
                    if (! hash_equals($image->sha256, hash('sha256', $oss->getBounded($config, $image->object_key, $image->size)))) {
                        throw new \RuntimeException;
                    }
                    $image->update(['oss_pending' => false, 'last_error' => null]);
                } elseif (! $image->backup_key) {
                    try {
                        $bytes = $oss->getBounded($config, $image->object_key, $image->size ?? 20 * 1024 * 1024);
                    } catch (\Throwable $failure) {
                        $disk = Storage::disk($image->source_disk);
                        if (! $disk->exists($image->source_key)) {
                            throw $failure;
                        }
                        $codec = 'plain';
                        foreach (app(ImageReferences::class)->all($image->tenant_id) as $ref) {
                            if ($ref['disk'] === $image->source_disk && $ref['key'] === $image->source_key) {
                                $codec = $ref['codec'];
                                break;
                            }
                        }
                        $bytes = $images->decode($disk->get($image->source_key), $codec);
                    }
                    $info = @getimagesizefromstring($bytes);
                    if (! $info || strlen($bytes) > ($image->size ?? 20 * 1024 * 1024)
                        || ($image->sha256 && ! hash_equals($image->sha256, hash('sha256', $bytes)))) {
                        throw new \RuntimeException;
                    }
                    $replicas->save($image, $bytes);
                    $image->update(['size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'last_error' => null]);
                }
                $this->line('Replicated '.$image->id);
            } catch (\Throwable) {
                $failed++;
                $image->update(['last_error' => 'REPLICA_RETRY_PENDING']);
                $this->warn('Replica pending '.$image->id);
            } finally {
                $lock->release();
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
