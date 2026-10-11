<?php

use App\Application\Cleanup\CleanupSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_cleanup_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $t->foreignUuid('actor_id')->constrained('admin_users')->restrictOnDelete();
            $t->jsonb('selection');
            $t->jsonb('summary');
            $t->string('fingerprint', 64);
            $t->string('status', 20)->default('PREVIEW');
            $t->uuid('request_id')->nullable();
            $t->integer('execution_pid')->nullable();
            $t->bigInteger('execution_txid')->nullable();
            $t->text('backup_path')->nullable();
            $t->string('backup_hash', 64)->nullable();
            $t->timestampTz('backup_expires_at')->nullable();
            $t->timestampTz('backup_deleted_at')->nullable();
            $t->timestampTz('expires_at');
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['actor_id', 'request_id']);
        });
        Schema::create('data_cleanup_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('batch_id')->constrained('data_cleanup_batches')->restrictOnDelete();
            $t->string('table_name');
            $t->jsonb('row_key');
            $t->string('row_hash', 32);
            $t->string('operation', 10)->default('DELETE');
            $t->jsonb('public_details');
            $t->unique(['batch_id', 'table_name', 'row_key']);
        });
        Schema::create('data_cleanup_tombstones', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('batch_id')->constrained('data_cleanup_batches')->restrictOnDelete();
            $t->uuid('tenant_id');
            $t->string('namespace');
            $t->string('identifier_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['namespace', 'identifier_hash']);
        });
        Schema::create('data_cleanup_images', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignUuid('batch_id')->constrained('data_cleanup_batches');
            $t->uuid('tenant_id');
            $t->text('location_encrypted');
            $t->string('location_hash', 64)->index();
            $t->timestampTz('delete_after');
            $t->timestampTz('deleted_at')->nullable();
        });
        DB::statement("ALTER TABLE data_cleanup_batches ADD CHECK (status IN ('PREVIEW','EXECUTING','COMPLETED'))");
        DB::statement("ALTER TABLE data_cleanup_items ADD CHECK (operation IN ('DELETE','RETIRE'))");
        DB::statement("ALTER TABLE data_cleanup_items ADD CHECK (table_name IN ('".implode("','", CleanupSchema::TABLES)."'))");
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
            CREATE TRIGGER cleanup_batch_guard BEFORE INSERT OR UPDATE OR DELETE ON data_cleanup_batches FOR EACH ROW EXECUTE FUNCTION guard_cleanup_batch();
            CREATE OR REPLACE FUNCTION cleanup_manifest_allows(target_table text, old_row jsonb, target_operation text DEFAULT 'DELETE') RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT EXISTS (
                    SELECT 1 FROM data_cleanup_items i JOIN data_cleanup_batches b ON b.id=i.batch_id
                    JOIN admin_users a ON a.id=b.actor_id AND a.status='ACTIVE'
                    JOIN admin_memberships m ON m.admin_user_id=a.id AND m.status='ACTIVE' AND m.scope_type='PLATFORM' AND m.scope_id IS NULL
                    JOIN roles r ON r.id=m.role_id AND r.scope_type='PLATFORM' AND r.name<>'SUPPORT_AGENT'
                    WHERE b.id::text=current_setting('card.cleanup_batch',true)
                    AND b.status='EXECUTING' AND b.execution_pid=pg_backend_pid() AND b.execution_txid=txid_current()
                    AND b.backup_hash IS NOT NULL AND b.backup_path IS NOT NULL
                    AND NOT EXISTS(SELECT 1 FROM support_agent_accounts s WHERE s.admin_id=a.id)
                    AND i.table_name=target_table AND i.operation=target_operation
                    AND old_row @> i.row_key AND md5(old_row::text)=i.row_hash
                    AND (NOT old_row ? 'tenant_id' OR old_row->>'tenant_id'=b.tenant_id::text
                        OR target_table='card_transaction_sync_batches' AND old_row->>'tenant_id' IS NULL
                        AND NOT EXISTS(SELECT 1 FROM card_transaction_sync_items x WHERE x.batch_id::text=old_row->>'id' AND x.tenant_id<>b.tenant_id))
                );
            $$;
            CREATE OR REPLACE FUNCTION guard_cleanup_manifest() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Cleanup manifest is immutable'; END IF;
                IF NOT EXISTS(SELECT 1 FROM data_cleanup_batches WHERE id=NEW.batch_id AND status='PREVIEW') THEN
                    RAISE EXCEPTION 'Cleanup manifest is already closed';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER cleanup_items_immutable BEFORE INSERT OR UPDATE OR DELETE ON data_cleanup_items FOR EACH ROW EXECUTE FUNCTION guard_cleanup_manifest();
            CREATE TRIGGER cleanup_tombstones_immutable BEFORE UPDATE OR DELETE ON data_cleanup_tombstones FOR EACH ROW EXECUTE FUNCTION reject_ledger_history_mutation();
            ALTER TABLE promotion_invitation_reservations ADD COLUMN cleanup_member_id uuid;
            SQL);
        // Retain globally reserved codes while detaching their deleted member FK.
        foreach (DB::select("SELECT conname FROM pg_constraint WHERE conrelid='promotion_invitation_reservations'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%member_id IS NULL%' AND pg_get_constraintdef(oid) LIKE '%company_invitation_id IS NULL%'") as $check) {
            DB::statement('ALTER TABLE promotion_invitation_reservations DROP CONSTRAINT "'.$check->conname.'"');
        }
        DB::statement('ALTER TABLE promotion_invitation_reservations ADD CHECK ((member_id IS NOT NULL)::integer + (company_invitation_id IS NOT NULL)::integer + (cleanup_member_id IS NOT NULL)::integer = 1)');
        $tables = CleanupSchema::TABLES;
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        // Preserve the original checking time. Cleanup may defer cycles without
        // disabling any FK; its exact-row history exception remains transaction bound.
        foreach (DB::select("SELECT ch.relname AS table_name,c.conname,pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_class ch ON ch.oid=c.conrelid JOIN pg_class pa ON pa.oid=c.confrelid WHERE c.contype='f' AND ch.relname IN ($placeholders) AND pa.relname IN ($placeholders)", [...$tables, ...$tables]) as $fk) {
            $timing = str_contains($fk->definition, 'INITIALLY DEFERRED') ? 'DEFERRED' : 'IMMEDIATE';
            $definition = preg_replace('/\s+ON DELETE RESTRICT|\s+NOT DEFERRABLE|\s+DEFERRABLE|\s+INITIALLY (?:DEFERRED|IMMEDIATE)/', '', $fk->definition);
            DB::statement('ALTER TABLE "'.$fk->table_name.'" DROP CONSTRAINT "'.$fk->conname.'"');
            DB::statement('ALTER TABLE "'.$fk->table_name.'" ADD CONSTRAINT "'.$fk->conname.'" '.$definition.' DEFERRABLE INITIALLY '.$timing);
        }
        // Preserve each existing trigger's ordinary behavior, adding only the
        // exact reviewed-row exception. No global trigger/replication bypass.
        foreach (DB::select("SELECT DISTINCT p.oid,pg_get_functiondef(p.oid) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_proc p ON p.oid=t.tgfoid JOIN pg_language l ON l.oid=p.prolang WHERE NOT t.tgisinternal AND l.lanname='plpgsql' AND c.relname IN ($placeholders) AND (t.tgtype & 8)=8", $tables) as $function) {
            $injection = "BEGIN\n IF TG_OP='DELETE' AND cleanup_manifest_allows(TG_TABLE_NAME,to_jsonb(OLD)) THEN RETURN OLD; END IF;\n";
            if (str_contains($function->definition, 'protect_invitation_evidence')) {
                $injection .= " IF TG_OP='UPDATE' AND TG_TABLE_NAME='promotion_invitation_reservations' AND cleanup_manifest_allows(TG_TABLE_NAME,to_jsonb(OLD),'RETIRE') AND OLD.member_id IS NOT NULL AND NEW.member_id IS NULL AND NEW.cleanup_member_id=OLD.member_id AND (to_jsonb(NEW)-ARRAY['member_id','cleanup_member_id'])=(to_jsonb(OLD)-ARRAY['member_id','cleanup_member_id']) THEN RETURN NEW; END IF;\n";
            }
            $definition = preg_replace('/\bBEGIN\b/i', $injection, $function->definition, 1, $count);
            if ($count !== 1) {
                throw new RuntimeException('Cleanup guard could not preserve an existing trigger.');
            }
            DB::unprepared($definition);
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_cleanup_identity_reuse() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS(SELECT 1 FROM data_cleanup_tombstones WHERE namespace='user-account:'||NEW.tenant_id::text AND identifier_hash=md5(NEW.account_id)) THEN
                    NEW.account_id=allocate_user_account_id(NEW.tenant_id,NEW.created_at);
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER zz_cleanup_account_reservation BEFORE INSERT ON users FOR EACH ROW EXECUTE FUNCTION guard_cleanup_identity_reuse();
            SQL);
        // The allocator must skip permanent reservations rather than merely retry.
        $allocator = DB::selectOne("SELECT pg_get_functiondef('allocate_user_account_id(uuid,timestamptz)'::regprocedure) AS definition")->definition;
        $allocator = str_replace('ORDER BY random() LIMIT 1;', "AND NOT EXISTS (SELECT 1 FROM data_cleanup_tombstones WHERE namespace='user-account:'||owner_tenant::text AND identifier_hash=md5(date_prefix || lpad(suffix::text,4,'0'))) ORDER BY random() LIMIT 1;", $allocator);
        DB::unprepared($allocator);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_cleanup_recreation() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE row_data jsonb; row_key jsonb; owner text; provider_account text; col text;
            BEGIN
                row_data=to_jsonb(NEW);
                SELECT jsonb_object_agg(a.attname,row_data->a.attname) INTO row_key
                FROM pg_index i JOIN LATERAL unnest(i.indkey) k(num) ON true
                JOIN pg_attribute a ON a.attrelid=i.indrelid AND a.attnum=k.num
                WHERE i.indrelid=TG_RELID AND i.indisprimary;
                IF EXISTS(SELECT 1 FROM data_cleanup_tombstones WHERE namespace='row:'||TG_TABLE_NAME AND identifier_hash=md5(row_key::text)) THEN
                    RAISE EXCEPTION 'Cleaned record cannot be recreated' USING ERRCODE='55000';
                END IF;
                owner=COALESCE(row_data->>'user_id',row_data->>'sender_user_id',row_data->>'actor_id','');
                IF row_data->>'request_id' IS NOT NULL AND EXISTS(SELECT 1 FROM data_cleanup_tombstones
                    WHERE namespace='request:'||TG_TABLE_NAME||':'||(row_data->>'tenant_id')||':'||owner AND identifier_hash=md5(row_data->>'request_id')) THEN
                    RAISE EXCEPTION 'Cleaned request cannot be replayed' USING ERRCODE='55000';
                END IF;
                IF TG_TABLE_NAME='ledger_entries' AND EXISTS(SELECT 1 FROM data_cleanup_tombstones
                    WHERE (namespace='resource' AND identifier_hash=md5(row_data->>'reference_id'))
                    OR (namespace='ledger-event:'||(row_data->>'tenant_id') AND identifier_hash=md5(row_data->>'event_key'))) THEN
                    RAISE EXCEPTION 'Cleaned financial operation cannot be replayed' USING ERRCODE='55000';
                END IF;
                IF TG_TABLE_NAME='inbox_events' AND EXISTS(SELECT 1 FROM regexp_split_to_table(row_data->>'event_key',':') AS part
                    JOIN data_cleanup_tombstones t ON t.namespace='resource' AND t.identifier_hash=md5(part)) THEN
                    RAISE EXCEPTION 'Cleaned notification source cannot be replayed' USING ERRCODE='55000';
                END IF;
                IF TG_TABLE_NAME='paid_promotion_events' AND EXISTS(SELECT 1 FROM data_cleanup_tombstones
                    WHERE namespace='promotion-event:'||(row_data->>'tenant_id')||':'||(row_data->>'kind') AND identifier_hash=md5(row_data->>'source_id')) THEN
                    RAISE EXCEPTION 'Cleaned commission source cannot be replayed' USING ERRCODE='55000';
                END IF;
                IF TG_TABLE_NAME IN ('asset_deposit_orders','asset_withdrawal_orders','asset_chain_observations') AND EXISTS(SELECT 1 FROM data_cleanup_tombstones
                    WHERE namespace='chain:'||(row_data->>'network') AND identifier_hash=md5(COALESCE(row_data->>'chain_event_id',row_data->>'event_id'))) THEN
                    RAISE EXCEPTION 'Cleaned chain receipt cannot be reused' USING ERRCODE='55000';
                END IF;
                IF TG_TABLE_NAME='wallet_topup_orders' AND EXISTS(SELECT 1 FROM data_cleanup_tombstones
                    WHERE namespace='chain:TRON' AND identifier_hash=md5(lower(row_data->>'matched_tx_hash')||':'||(row_data->>'matched_transfer_index'))) THEN
                    RAISE EXCEPTION 'Cleaned chain receipt cannot be reused' USING ERRCODE='55000';
                END IF;
                IF TG_TABLE_NAME IN ('payment_provider_transactions','payment_provider_events') THEN
                    FOREACH col IN ARRAY ARRAY['provider_request_id','provider_transaction_id','provider_event_key'] LOOP
                        IF EXISTS(SELECT 1 FROM data_cleanup_tombstones WHERE namespace='payment:'||(row_data->>'provider')||':'||col AND identifier_hash=md5(row_data->>col)) THEN
                            RAISE EXCEPTION 'Cleaned payment identifier cannot be reused' USING ERRCODE='55000';
                        END IF;
                    END LOOP;
                END IF;
                IF TG_TABLE_NAME IN ('user_cards','provider_cardholders','card_issue_orders','card_management_orders','card_recipient_applications') THEN
                    SELECT p.card_provider_reference_id::text INTO provider_account FROM card_products p
                        WHERE p.id::text=row_data->>'card_product_id';
                    IF provider_account IS NULL AND row_data->>'card_id' IS NOT NULL THEN
                        SELECT p.card_provider_reference_id::text INTO provider_account FROM card_products p JOIN user_cards c ON c.card_product_id=p.id WHERE c.id::text=row_data->>'card_id';
                    END IF;
                    provider_account=COALESCE(provider_account,'');
                    FOREACH col IN ARRAY ARRAY['provider_card_id','provider_cardholder_id','provider_request_id','provider_recipient_id'] LOOP
                        IF EXISTS(SELECT 1 FROM data_cleanup_tombstones WHERE namespace IN ('card:'||provider_account||':'||col,'card-global:'||COALESCE(row_data->>'provider','PHOTONPAY')||':'||col) AND identifier_hash=md5(row_data->>col)) THEN
                            RAISE EXCEPTION 'Cleaned provider identifier cannot be reused' USING ERRCODE='55000';
                        END IF;
                    END LOOP;
                END IF;
                RETURN NEW;
            END $$;
            SQL);
        foreach ($tables as $table) {
            DB::statement('CREATE TRIGGER zz_cleanup_recreation BEFORE INSERT ON '.CleanupSchema::quoted($table).' FOR EACH ROW EXECUTE FUNCTION guard_cleanup_recreation()');
        }
        foreach (['wallet_topup_orders', 'asset_deposit_orders', 'asset_withdrawal_orders', 'provider_cardholders', 'user_cards', 'card_issue_orders', 'card_management_orders'] as $table) {
            DB::statement('CREATE TRIGGER zz_cleanup_reuse_on_update BEFORE UPDATE ON '.CleanupSchema::quoted($table).' FOR EACH ROW EXECUTE FUNCTION guard_cleanup_recreation()');
        }
        $permission = (string) Str::uuid();
        DB::table('permissions')->insert(['id' => $permission, 'name' => 'users.cleanup', 'description' => 'Preview and permanently clean explicitly selected account data', 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->pluck('id') as $role) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Cleanup evidence and permanent identity reservations require forward migrations.');
    }
};
