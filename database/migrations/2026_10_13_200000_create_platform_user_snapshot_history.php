<?php

use App\Application\Cleanup\CleanupSchema;
use App\Application\User\Snapshots\UserSnapshotSources;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One atomic baseline; all reporting writers pause until triggers are installed.
        $tables = array_keys(UserSnapshotSources::COLUMNS);
        sort($tables);
        DB::statement("SET LOCAL lock_timeout = '10s'");
        DB::statement('LOCK TABLE '.implode(',', $tables).' IN SHARE ROW EXCLUSIVE MODE');
        Schema::create('platform_user_snapshot_batches', function (Blueprint $t) {
            $t->string('transaction_id', 32)->primary();
            $t->timestampTz('published_at', 6)->index();
            $t->boolean('baseline')->default(false);
        });
        Schema::create('platform_user_snapshot_sources', function (Blueprint $t) {
            $t->string('table_name')->primary();
            $t->jsonb('columns');
            $t->jsonb('primary_keys');
        });
        Schema::create('platform_user_history', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('table_name');
            $t->jsonb('source_key');
            $t->uuid('tenant_id')->nullable();
            $t->uuid('user_id')->nullable();
            $t->string('transaction_id', 32);
            $t->boolean('deleted')->default(false);
            $t->jsonb('row_data');
            $t->index(['table_name', 'transaction_id']);
            $t->index(['tenant_id', 'user_id']);
        });
        foreach (DB::select("SELECT conname FROM pg_constraint WHERE conrelid='data_cleanup_items'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%table_name%'") as $check) {
            DB::statement('ALTER TABLE data_cleanup_items DROP CONSTRAINT "'.$check->conname.'"');
        }
        DB::statement("ALTER TABLE data_cleanup_items ADD CHECK (table_name IN ('".implode("','", CleanupSchema::TABLES)."'))");
        DB::statement('CREATE INDEX platform_user_history_version ON platform_user_history(table_name, source_key, id DESC)');
        DB::statement('CREATE INDEX platform_user_history_transaction ON platform_user_history(transaction_id, id DESC)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION capture_platform_user_history() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE payload jsonb; projection jsonb; source_identity jsonb; config record;
            BEGIN
                IF TG_OP='DELETE' AND cleanup_manifest_allows(TG_TABLE_NAME,to_jsonb(OLD)) THEN RETURN OLD; END IF;
                payload := CASE WHEN TG_OP='DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
                SELECT * INTO STRICT config FROM public.platform_user_snapshot_sources WHERE table_name=TG_TABLE_NAME;
                SELECT jsonb_object_agg(c,payload->c) INTO projection FROM jsonb_array_elements_text(config.columns) c;
                IF TG_OP='UPDATE' THEN
                    IF projection = (SELECT jsonb_object_agg(c,to_jsonb(OLD)->c) FROM jsonb_array_elements_text(config.columns) c) THEN RETURN NEW; END IF;
                END IF;
                SELECT jsonb_object_agg(k,payload->k) INTO source_identity FROM jsonb_array_elements_text(config.primary_keys) k;
                INSERT INTO public.platform_user_history(table_name,source_key,tenant_id,user_id,transaction_id,deleted,row_data)
                VALUES(TG_TABLE_NAME,source_identity,(payload->>'tenant_id')::uuid,
                    CASE WHEN TG_TABLE_NAME='users' THEN (payload->>'id')::uuid ELSE (payload->>'user_id')::uuid END,
                    pg_current_xact_id()::text,TG_OP='DELETE',projection);
                RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
            END $$;
            CREATE OR REPLACE FUNCTION publish_platform_user_history() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                -- Only the final queued row publishes the complete transaction. This
                -- avoids repeatedly updating one indexed batch for bulk registrations.
                IF NEW.id IS DISTINCT FROM (SELECT MAX(id) FROM public.platform_user_history WHERE transaction_id=NEW.transaction_id) THEN RETURN NEW; END IF;
                INSERT INTO public.platform_user_snapshot_batches(transaction_id,published_at,baseline)
                VALUES(NEW.transaction_id,clock_timestamp(),false) ON CONFLICT(transaction_id) DO UPDATE
                    SET published_at=EXCLUDED.published_at WHERE NOT platform_user_snapshot_batches.baseline;
                RETURN NEW;
            END $$;
            CREATE CONSTRAINT TRIGGER platform_user_history_publish AFTER INSERT ON platform_user_history
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION publish_platform_user_history();
            CREATE OR REPLACE FUNCTION protect_platform_user_history() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='DELETE' AND cleanup_manifest_allows(TG_TABLE_NAME,to_jsonb(OLD)) THEN RETURN OLD; END IF;
                RAISE EXCEPTION 'User snapshot history is immutable';
            END $$;
            CREATE TRIGGER platform_user_history_immutable BEFORE UPDATE OR DELETE ON platform_user_history
                FOR EACH ROW EXECUTE FUNCTION protect_platform_user_history();
            CREATE TRIGGER platform_user_history_no_truncate BEFORE TRUNCATE ON platform_user_history
                FOR EACH STATEMENT EXECUTE FUNCTION protect_platform_user_history();
            CREATE OR REPLACE FUNCTION protect_platform_user_snapshot_batch() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='UPDATE' AND OLD.transaction_id=pg_current_xact_id()::text AND NOT OLD.baseline
                    AND NEW.published_at>=OLD.published_at
                    AND (to_jsonb(NEW)-'published_at')=(to_jsonb(OLD)-'published_at') THEN RETURN NEW; END IF;
                RAISE EXCEPTION 'Published user snapshot batches are immutable';
            END $$;
            CREATE TRIGGER platform_user_snapshot_batches_immutable BEFORE UPDATE OR DELETE ON platform_user_snapshot_batches
                FOR EACH ROW EXECUTE FUNCTION protect_platform_user_snapshot_batch();
            CREATE TRIGGER platform_user_snapshot_batches_no_truncate BEFORE TRUNCATE ON platform_user_snapshot_batches
                FOR EACH STATEMENT EXECUTE FUNCTION reject_ledger_history_mutation();
            CREATE TRIGGER platform_user_snapshot_sources_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON platform_user_snapshot_sources
                FOR EACH STATEMENT EXECUTE FUNCTION reject_ledger_history_mutation();
            SQL);
        foreach (UserSnapshotSources::COLUMNS as $table => $columns) {
            $actual = Schema::getColumnListing($table);
            if (array_diff($columns, $actual)) {
                throw new RuntimeException('Incomplete user snapshot source: '.$table);
            }
            $keys = array_map(fn ($r) => $r->column_name, DB::select('SELECT a.attname AS column_name FROM pg_index i JOIN pg_attribute a ON a.attrelid=i.indrelid AND a.attnum=ANY(i.indkey) WHERE i.indrelid=?::regclass AND i.indisprimary ORDER BY a.attnum', [$table]));
            if ($keys === [] || array_diff($keys, $columns)) {
                throw new RuntimeException('Missing user snapshot identity: '.$table);
            }
            DB::table('platform_user_snapshot_sources')->insert(['table_name' => $table, 'columns' => json_encode($columns), 'primary_keys' => json_encode($keys)]);
            $projection = implode(',', array_map(fn ($c) => "'$c',to_jsonb(s)->'$c'", $columns));
            $identity = implode(',', array_map(fn ($c) => "'$c',to_jsonb(s)->'$c'", $keys));
            $owner = $table === 'users' ? "(to_jsonb(s)->>'id')::uuid" : "(to_jsonb(s)->>'user_id')::uuid";
            DB::statement("INSERT INTO platform_user_history(table_name,source_key,tenant_id,user_id,transaction_id,row_data)
                SELECT '$table',jsonb_build_object($identity),(to_jsonb(s)->>'tenant_id')::uuid,$owner,pg_current_xact_id()::text,jsonb_build_object($projection) FROM $table s");
            DB::statement("CREATE TRIGGER zz_platform_user_history_no_truncate BEFORE TRUNCATE ON $table FOR EACH STATEMENT EXECUTE FUNCTION reject_ledger_history_mutation()");
            DB::statement("CREATE TRIGGER zz_platform_user_history AFTER INSERT OR UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION capture_platform_user_history()");
        }
        DB::statement('INSERT INTO platform_user_snapshot_batches(transaction_id,published_at,baseline) VALUES(pg_current_xact_id()::text,clock_timestamp(),true)');
    }

    public function down(): void
    {
        throw new RuntimeException('User snapshot evidence requires a forward migration.');
    }
};
