<?php

namespace App\Console\Commands;

use App\Application\Media\ImageReferences;
use App\Application\Media\ImageStorage;
use App\Application\Media\MigrateImages;
use App\Domain\Media\StoredImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MigrateOssImages extends Command
{
    protected $signature = 'images:migrate-oss {--execute} {--tenant=} {--limit=100} {--retry-failed}';

    protected $description = 'Preview or migrate referenced images to OSS, preserving original files and business rows';

    public function handle(ImageReferences $refs, ImageStorage $images, MigrateImages $migration): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! $limit || $limit < 1 || $limit > 1000 || ($this->option('tenant') && ! Str::isUuid($this->option('tenant')))) {
            $this->error('Use limit 1–1000 and a valid company UUID.');

            return self::FAILURE;
        }
        // PostgreSQL session lock is released automatically if the worker dies.
        if (! DB::selectOne('select pg_try_advisory_lock(20260927, 140000) as acquired')->acquired) {
            $this->error('Image migration already running.');

            return self::FAILURE;
        }
        $summary = ['mode' => $this->option('execute') ? 'execute' : 'preview', 'eligible' => 0, 'migrated' => 0, 'failed' => 0, 'previous_failures_skipped' => 0];
        try {
            if ($this->option('execute') && ! $images->active()?->verified_at) {
                $this->error('Enable a verified OSS configuration first.');

                return self::FAILURE;
            }
            foreach ($refs->all($this->option('tenant')) as $ref) {
                $record = $images->record($ref['disk'], $ref['key']);
                if ($record?->configuration_id) {
                    continue;
                }
                if ($record?->last_error === 'MIGRATION_FAILED' && ! $this->option('retry-failed')) {
                    $summary['previous_failures_skipped']++;

                    continue;
                }
                $summary['eligible']++;
                if ($this->option('execute')) {
                    try {
                        $migration->migrate($ref);
                        $summary['migrated']++;
                    } catch (\Throwable) {
                        $summary['failed']++;
                        StoredImage::firstOrCreate(['source_disk' => $ref['disk'], 'source_key' => $ref['key']],
                            ['tenant_id' => $ref['tenant'], 'purpose' => $ref['purpose'], 'business_reference' => $ref['reference'], 'object_key' => $ref['key'],
                                'mime' => 'application/octet-stream', 'size' => 0, 'sha256' => str_repeat('0', 64), 'codec' => $ref['codec'], 'state' => 'ready'])
                            ->update(['last_error' => 'MIGRATION_FAILED']);
                        $this->warn('Failed image reference: '.$ref['purpose'].'/'.$ref['reference']);
                    }
                }
                if ($summary['eligible'] >= $limit) {
                    break;
                }
            }
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));
            $this->info('Repeat to continue; use --retry-failed to revisit failures. No local files are deleted.');

            return $summary['failed'] ? self::FAILURE : self::SUCCESS;
        } finally {
            DB::select('select pg_advisory_unlock(20260927, 140000)');
        }
    }
}
