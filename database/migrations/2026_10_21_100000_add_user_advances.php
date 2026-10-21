<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_advance_entries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->references('id')->on('tenants');
            $t->uuid('user_id');
            $t->decimal('amount', 26, 8);
            $t->date('business_date');
            $t->text('note');
            $t->uuid('reverses_id')->nullable()->unique();
            $t->uuid('actor_id')->references('id')->on('admin_users');
            $t->uuid('request_id');
            $t->char('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['id', 'tenant_id', 'user_id']);
            $t->unique(['tenant_id', 'request_id']);
            $t->index(['tenant_id', 'user_id', 'created_at']);
            $t->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            $t->foreign(['reverses_id', 'tenant_id', 'user_id'])->references(['id', 'tenant_id', 'user_id'])->on('user_advance_entries');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE user_advance_entries ADD CHECK(amount <> 0);
            CREATE OR REPLACE FUNCTION guard_user_advance() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
                IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'User advances are append-only'; END IF;
                IF NEW.reverses_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM user_advance_entries j
                    WHERE j.id=NEW.reverses_id AND j.tenant_id=NEW.tenant_id AND j.user_id=NEW.user_id
                    AND j.reverses_id IS NULL AND j.amount=NEW.amount)
                THEN RAISE EXCEPTION 'Invalid user advance reversal'; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER user_advance_guard BEFORE INSERT OR UPDATE OR DELETE ON user_advance_entries FOR EACH ROW EXECUTE FUNCTION guard_user_advance();
            SQL);
        foreach (['wallet_topup_orders', 'asset_deposit_orders'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN user_advance_journal_id uuid NULL UNIQUE,
                ADD FOREIGN KEY(user_advance_journal_id,tenant_id,user_id) REFERENCES user_advance_entries(id,tenant_id,user_id),
                DROP CONSTRAINT {$table}_receipt_type,
                ADD CONSTRAINT {$table}_receipt_type CHECK (
                    (manual_receipt_type IS NULL AND advance_journal_id IS NULL AND user_advance_journal_id IS NULL) OR
                    (manual_confirmed_at IS NOT NULL AND manual_receipt_type IS NOT NULL AND
                      ((manual_receipt_type='ACTUAL' AND advance_journal_id IS NULL AND user_advance_journal_id IS NULL) OR
                       (manual_receipt_type='ADVANCE' AND asset_code='USDT' AND num_nonnulls(advance_journal_id,user_advance_journal_id)=1))))");
        }
        foreach (['before_user_journal_id', 'after_user_journal_id', 'reversal_user_journal_id'] as $column) {
            DB::statement("ALTER TABLE deposit_receipt_type_changes ADD COLUMN {$column} uuid NULL,
                ADD FOREIGN KEY({$column},tenant_id,user_id) REFERENCES user_advance_entries(id,tenant_id,user_id)");
        }
        DB::statement('ALTER TABLE deposit_receipt_type_changes ADD UNIQUE(after_user_journal_id)');
        DB::unprepared(<<<'SQL'
            ALTER TABLE deposit_receipt_type_changes DROP CONSTRAINT deposit_receipt_change_shape;
            ALTER TABLE deposit_receipt_type_changes ADD CONSTRAINT deposit_receipt_change_shape CHECK (
                source IN ('primary','asset') AND revision>0 AND asset_code='USDT' AND amount>0 AND
                before_type IN ('ACTUAL','ADVANCE') AND after_type IN ('ACTUAL','ADVANCE') AND before_type<>after_type AND
                ((before_type='ACTUAL' AND num_nonnulls(before_journal_id,before_user_journal_id,reversal_journal_id,reversal_user_journal_id)=0) OR
                 (before_type='ADVANCE' AND ((before_journal_id IS NOT NULL AND reversal_journal_id IS NOT NULL AND before_user_journal_id IS NULL AND reversal_user_journal_id IS NULL) OR
                    (before_user_journal_id IS NOT NULL AND reversal_user_journal_id IS NOT NULL AND before_journal_id IS NULL AND reversal_journal_id IS NULL)))) AND
                ((after_type='ACTUAL' AND num_nonnulls(after_journal_id,after_user_journal_id)=0) OR
                 (after_type='ADVANCE' AND num_nonnulls(after_journal_id,after_user_journal_id)=1))
            );
            CREATE OR REPLACE FUNCTION protect_manual_receipt_type() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
                IF (OLD.manual_confirmed_at IS NOT NULL OR OLD.status='CREDITED') AND
                    ROW(NEW.manual_receipt_type,NEW.advance_journal_id,NEW.user_advance_journal_id) IS DISTINCT FROM ROW(OLD.manual_receipt_type,OLD.advance_journal_id,OLD.user_advance_journal_id)
                THEN RAISE EXCEPTION 'Manual receipt classification is immutable'; END IF;
                RETURN NEW;
            END $$;
            SQL);
        $definition = DB::selectOne("SELECT pg_get_functiondef('protect_asset_order()'::regprocedure) AS definition")->definition;
        DB::unprepared(str_replace("'advance_journal_id',", "'advance_journal_id','user_advance_journal_id',", $definition));
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_deposit_receipt_type_change() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE facts jsonb; previous deposit_receipt_type_changes%ROWTYPE; expected_type text; expected_journal uuid; expected_user_journal uuid;
            BEGIN
                IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Deposit receipt type changes are immutable'; END IF;
                IF NEW.source='primary' THEN
                    SELECT to_jsonb(o) INTO facts FROM wallet_topup_orders o WHERE id=NEW.order_id AND tenant_id=NEW.tenant_id FOR UPDATE;
                ELSE
                    SELECT to_jsonb(o) INTO facts FROM asset_deposit_orders o WHERE id=NEW.order_id AND tenant_id=NEW.tenant_id FOR UPDATE;
                END IF;
                IF facts IS NULL OR facts->>'status'<>'CREDITED'  OR facts->>'ledger_entry_id' IS NULL
                    OR facts->>'user_id' IS DISTINCT FROM NEW.user_id::text OR facts->>'asset_code' IS DISTINCT FROM NEW.asset_code
                    OR COALESCE(facts->>'actual_received_amount', facts->>'amount')::numeric IS DISTINCT FROM NEW.amount
                THEN RAISE EXCEPTION 'Only settled receipts may change classification'; END IF;
                SELECT * INTO previous FROM deposit_receipt_type_changes WHERE source=NEW.source AND order_id=NEW.order_id ORDER BY revision DESC LIMIT 1;
                expected_type := COALESCE(previous.after_type, facts->>'manual_receipt_type', 'ACTUAL');
                expected_journal := CASE WHEN previous.id IS NOT NULL THEN previous.after_journal_id ELSE (facts->>'advance_journal_id')::uuid END;
                expected_user_journal := CASE WHEN previous.id IS NOT NULL THEN previous.after_user_journal_id ELSE (facts->>'user_advance_journal_id')::uuid END;
                IF NEW.before_user_journal_id IS DISTINCT FROM expected_user_journal THEN RAISE EXCEPTION 'User advance revision is stale'; END IF;
                IF NEW.revision <> COALESCE(previous.revision,0)+1 OR NEW.before_type IS DISTINCT FROM expected_type
                    OR NEW.before_journal_id IS DISTINCT FROM expected_journal
                THEN RAISE EXCEPTION 'Deposit receipt revision is stale'; END IF;
                IF NEW.after_type='ADVANCE' AND NOT (EXISTS (
                    SELECT 1 FROM user_advance_entries j WHERE j.id=NEW.after_user_journal_id AND j.tenant_id=NEW.tenant_id AND j.user_id=NEW.user_id
                    AND j.reverses_id IS NULL AND j.amount=NEW.amount AND NOT EXISTS(SELECT 1 FROM user_advance_entries r WHERE r.reverses_id=j.id)
                ) OR EXISTS (
                    SELECT 1 FROM partner_journal_entries j JOIN partner_configurations p ON p.id=j.partner_id AND p.tenant_id=j.tenant_id
                    WHERE j.id=NEW.after_journal_id AND j.tenant_id=NEW.tenant_id AND p.user_id=NEW.user_id AND p.enabled
                        AND j.kind='ADVANCE' AND j.reverses_id IS NULL AND j.amount=NEW.amount
                        AND NOT EXISTS (SELECT 1 FROM partner_journal_entries r WHERE r.reverses_id=j.id)
                )) THEN RAISE EXCEPTION 'Advance classification requires matching active partner evidence'; END IF;
                IF NEW.before_type='ADVANCE' AND NOT (EXISTS (
                    SELECT 1 FROM user_advance_entries r JOIN user_advance_entries j ON j.id=r.reverses_id AND j.tenant_id=r.tenant_id AND j.user_id=r.user_id
                    WHERE r.id=NEW.reversal_user_journal_id AND j.id=NEW.before_user_journal_id AND j.tenant_id=NEW.tenant_id AND j.user_id=NEW.user_id AND j.amount=NEW.amount AND r.amount=NEW.amount
                ) OR EXISTS (
                    SELECT 1 FROM partner_journal_entries r JOIN partner_journal_entries j ON j.id=r.reverses_id AND j.tenant_id=r.tenant_id
                    JOIN partner_configurations p ON p.id=j.partner_id AND p.tenant_id=j.tenant_id
                    WHERE r.id=NEW.reversal_journal_id AND r.tenant_id=NEW.tenant_id AND j.id=NEW.before_journal_id
                        AND p.user_id=NEW.user_id AND j.kind='ADVANCE' AND r.kind='ADVANCE' AND j.amount=NEW.amount AND r.amount=NEW.amount
                )) THEN RAISE EXCEPTION 'Manual classification requires matching advance reversal'; END IF;
                RETURN NEW;
            END $$;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('User advance evidence requires a coordinated forward migration; rollback is not supported.');
    }
};
