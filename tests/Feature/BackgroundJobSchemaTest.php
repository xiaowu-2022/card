<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('repairs drifted background schemas idempotently and retains failed job records', function (): void {
    DB::statement('ALTER TABLE data_cleanup_batches DROP COLUMN backup_deleted_at');
    Schema::dropIfExists('failed_jobs');
    $migration = require database_path('migrations/2026_10_14_170000_repair_background_job_schema.php');
    $migration->up();
    expect(Schema::hasColumn('data_cleanup_batches', 'backup_deleted_at'))->toBeTrue();
    DB::table('failed_jobs')->insert(['uuid' => 'offline-failed-job', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'Offline fixture']);
    $migration->up();
    expect(DB::table('failed_jobs')->where('uuid', 'offline-failed-job')->count())->toBe(1);
});

it('stops backup expiry before any cleanup when the schema is incomplete', function (): void {
    DB::statement('ALTER TABLE data_cleanup_batches DROP COLUMN backup_deleted_at');
    $this->artisan('data-cleanup:expire-backups')->expectsOutputToContain('Cleanup schema is incomplete')->assertFailed();
});
