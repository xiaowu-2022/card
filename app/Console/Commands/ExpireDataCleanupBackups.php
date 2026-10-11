<?php

namespace App\Console\Commands;

use App\Application\Cleanup\CleanupCompanyFence;
use App\Application\Cleanup\RemoveCleanedPendingJobs;
use App\Application\Media\ImageReferences;
use App\Application\Media\ImageStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ExpireDataCleanupBackups extends Command
{
    protected $signature = 'data-cleanup:expire-backups {--limit=100}';

    protected $description = 'Remove expired private cleanup backups and proven unreferenced images';

    public function handle(ImageReferences $references, ImageStorage $images): int
    {
        try {
            app(RemoveCleanedPendingJobs::class)->run();
        } catch (\Throwable) {
            $this->warn('Pending task cleanup deferred.');
        }
        $limit = max(1, min(1000, (int) $this->option('limit')));
        foreach (DB::table('data_cleanup_images')->whereNull('deleted_at')->where('delete_after', '<=', now())->orderBy('id')->limit($limit)->get() as $record) {
            try {
                app(CleanupCompanyFence::class)->run($record->tenant_id, function () use ($record, $references, $images): void {
                    if (DB::table('data_cleanup_images')->where('location_hash', $record->location_hash)->where('delete_after', '>', now())->exists()) {
                        return; // Another retained backup still needs this same private image.
                    }
                    $location = json_decode(Crypt::decryptString($record->location_encrypted), true, flags: JSON_THROW_ON_ERROR);
                    if ($references->contains(null, $location['disk'], $location['key'])) {
                        return;
                    }
                    $images->discard($location['disk'], $location['key']);
                    $pending = DB::table('stored_images')->where('tenant_id', $record->tenant_id)->where('source_disk', $location['disk'])->where('source_key', $location['key'])->where('state', '<>', 'deleted')->exists();
                    if (! $pending) {
                        DB::table('data_cleanup_images')->where('id', $record->id)->update(['deleted_at' => now()]);
                    }
                }, exclusive: true);
            } catch (\Throwable) {
                $this->warn('Private image cleanup deferred.');
            }
        }
        foreach (DB::table('data_cleanup_batches')->where('status', 'COMPLETED')->whereNull('backup_deleted_at')->where('backup_expires_at', '<=', now())->whereNotNull('backup_path')->orderBy('backup_expires_at')->limit($limit)->get() as $batch) {
            if (Storage::disk('private')->delete($batch->backup_path)) {
                DB::table('data_cleanup_batches')->where('id', $batch->id)->whereNull('backup_deleted_at')->update(['backup_deleted_at' => now(), 'updated_at' => now()]);
            } else {
                $this->warn('Private backup cleanup deferred.');
            }
        }
        // Failed transactions may have left a verified file without a committed
        // backup pointer. Expire only this feature's UUID-named private files.
        foreach (array_slice(Storage::disk('private')->files('data-cleanup'), 0, $limit) as $path) {
            if (preg_match('~^data-cleanup/[a-f0-9-]{36}\.enc$~', $path) === 1
                && ! DB::table('data_cleanup_batches')->where('backup_path', $path)->exists()
                && Storage::disk('private')->lastModified($path) <= now()->subDays(7)->timestamp) {
                Storage::disk('private')->delete($path);
            }
        }

        return self::SUCCESS;
    }
}
