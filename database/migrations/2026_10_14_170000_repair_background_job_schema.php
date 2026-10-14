<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('data_cleanup_batches')) {
            throw new RuntimeException('Apply the scoped data cleanup migration first.');
        }
        if (! Schema::hasColumn('data_cleanup_batches', 'backup_deleted_at')) {
            Schema::table('data_cleanup_batches', fn (Blueprint $table) => $table->timestampTz('backup_deleted_at')->nullable());
        }
        // Restore the current guard without touching evidence or disabling any triggers.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_cleanup_batch() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Cleanup batch is immutable'; END IF;
                IF TG_OP='INSERT' THEN
                    IF NEW.status<>'PREVIEW' OR NEW.request_id IS NOT NULL OR NEW.backup_path IS NOT NULL OR NEW.execution_pid IS NOT NULL OR NEW.backup_deleted_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Cleanup batch requires a preview';
                    END IF;
                    RETURN NEW;
                END IF;
                IF (NEW.id,NEW.tenant_id,NEW.actor_id,NEW.selection,NEW.summary,NEW.fingerprint,NEW.expires_at,NEW.created_at)
                    IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.actor_id,OLD.selection,OLD.summary,OLD.fingerprint,OLD.expires_at,OLD.created_at) THEN
                    RAISE EXCEPTION 'Cleanup preview is immutable';
                END IF;
                IF OLD.status='COMPLETED' AND OLD.backup_deleted_at IS NULL AND NEW.backup_deleted_at>=OLD.backup_expires_at
                    AND (to_jsonb(NEW)-ARRAY['backup_deleted_at','updated_at'])=(to_jsonb(OLD)-ARRAY['backup_deleted_at','updated_at']) THEN RETURN NEW; END IF;
                IF OLD.status='PREVIEW' AND NEW.status='EXECUTING' AND NEW.execution_pid=pg_backend_pid() AND NEW.execution_txid=txid_current()
                    AND NEW.backup_path IS NOT NULL AND NEW.backup_hash IS NOT NULL AND NEW.backup_expires_at IS NOT NULL
                    AND NEW.request_id IS NOT NULL AND NEW.completed_at IS NULL AND NEW.backup_deleted_at IS NULL AND NEW.expires_at>now() THEN RETURN NEW; END IF;
                IF OLD.status='EXECUTING' AND NEW.status='COMPLETED' AND OLD.execution_pid=pg_backend_pid() AND OLD.execution_txid=txid_current()
                    AND OLD.id::text=current_setting('card.cleanup_batch',true) AND NEW.execution_pid IS NULL AND NEW.execution_txid IS NULL
                    AND NEW.completed_at IS NOT NULL AND NEW.backup_deleted_at IS NULL AND (NEW.request_id,NEW.backup_path,NEW.backup_hash,NEW.backup_expires_at)
                    IS NOT DISTINCT FROM (OLD.request_id,OLD.backup_path,OLD.backup_hash,OLD.backup_expires_at) THEN RETURN NEW; END IF;
                RAISE EXCEPTION 'Invalid cleanup batch transition';
            END $$;
SQL);
        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestampTz('failed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Background job evidence requires a forward migration.');
    }
};
