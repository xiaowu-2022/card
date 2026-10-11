<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_receipt_type_changes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->references('id')->on('tenants');
            $t->uuid('user_id');
            $t->string('source', 10);
            $t->uuid('order_id');
            $t->unsignedInteger('revision');
            $t->string('before_type', 10);
            $t->string('after_type', 10);
            $t->decimal('amount', 36, 18);
            $t->string('asset_code', 10);
            $t->uuid('before_journal_id')->nullable();
            $t->uuid('after_journal_id')->nullable()->unique();
            $t->uuid('reversal_journal_id')->nullable();
            $t->uuid('actor_id')->references('id')->on('admin_users');
            $t->uuid('request_id');
            $t->char('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['source', 'order_id', 'revision'], 'deposit_receipt_revision_unique');
            $t->unique(['actor_id', 'request_id'], 'deposit_receipt_request_unique');
            $t->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            foreach (['before_journal_id', 'after_journal_id', 'reversal_journal_id'] as $column) {
                $t->foreign([$column, 'tenant_id'])->references(['id', 'tenant_id'])->on('partner_journal_entries');
            }
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE deposit_receipt_type_changes ADD CONSTRAINT deposit_receipt_change_shape CHECK (
                source IN ('primary','asset') AND revision > 0 AND asset_code='USDT' AND amount > 0 AND
                before_type IN ('ACTUAL','ADVANCE') AND after_type IN ('ACTUAL','ADVANCE') AND before_type <> after_type AND
                ((before_type='ACTUAL' AND before_journal_id IS NULL AND reversal_journal_id IS NULL) OR
                 (before_type='ADVANCE' AND before_journal_id IS NOT NULL AND reversal_journal_id IS NOT NULL)) AND
                ((after_type='ACTUAL' AND after_journal_id IS NULL) OR (after_type='ADVANCE' AND after_journal_id IS NOT NULL))
            );
            CREATE OR REPLACE FUNCTION guard_deposit_receipt_type_change() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE facts jsonb; previous deposit_receipt_type_changes%ROWTYPE; expected_type text; expected_journal uuid;
            BEGIN
                IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Deposit receipt type changes are immutable'; END IF;
                IF NEW.source='primary' THEN
                    SELECT to_jsonb(o) INTO facts FROM wallet_topup_orders o WHERE id=NEW.order_id AND tenant_id=NEW.tenant_id FOR UPDATE;
                ELSE
                    SELECT to_jsonb(o) INTO facts FROM asset_deposit_orders o WHERE id=NEW.order_id AND tenant_id=NEW.tenant_id FOR UPDATE;
                END IF;
                IF facts IS NULL OR facts->>'status'<>'CREDITED' OR facts->>'manual_confirmed_at' IS NULL OR facts->>'ledger_entry_id' IS NULL
                    OR facts->>'user_id' IS DISTINCT FROM NEW.user_id::text OR facts->>'asset_code' IS DISTINCT FROM NEW.asset_code
                    OR COALESCE(facts->>'actual_received_amount', facts->>'amount')::numeric IS DISTINCT FROM NEW.amount
                THEN RAISE EXCEPTION 'Only settled manual receipts may change classification'; END IF;
                SELECT * INTO previous FROM deposit_receipt_type_changes WHERE source=NEW.source AND order_id=NEW.order_id ORDER BY revision DESC LIMIT 1;
                expected_type := COALESCE(previous.after_type, facts->>'manual_receipt_type', 'ACTUAL');
                expected_journal := CASE WHEN previous.id IS NOT NULL THEN previous.after_journal_id ELSE (facts->>'advance_journal_id')::uuid END;
                IF NEW.revision <> COALESCE(previous.revision,0)+1 OR NEW.before_type IS DISTINCT FROM expected_type
                    OR NEW.before_journal_id IS DISTINCT FROM expected_journal
                THEN RAISE EXCEPTION 'Deposit receipt revision is stale'; END IF;
                IF NEW.after_type='ADVANCE' AND NOT EXISTS (
                    SELECT 1 FROM partner_journal_entries j JOIN partner_configurations p ON p.id=j.partner_id AND p.tenant_id=j.tenant_id
                    WHERE j.id=NEW.after_journal_id AND j.tenant_id=NEW.tenant_id AND p.user_id=NEW.user_id AND p.enabled
                        AND j.kind='ADVANCE' AND j.reverses_id IS NULL AND j.amount=NEW.amount
                        AND NOT EXISTS (SELECT 1 FROM partner_journal_entries r WHERE r.reverses_id=j.id)
                ) THEN RAISE EXCEPTION 'Advance classification requires matching active partner evidence'; END IF;
                IF NEW.before_type='ADVANCE' AND NOT EXISTS (
                    SELECT 1 FROM partner_journal_entries r JOIN partner_journal_entries j ON j.id=r.reverses_id AND j.tenant_id=r.tenant_id
                    JOIN partner_configurations p ON p.id=j.partner_id AND p.tenant_id=j.tenant_id
                    WHERE r.id=NEW.reversal_journal_id AND r.tenant_id=NEW.tenant_id AND j.id=NEW.before_journal_id
                        AND p.user_id=NEW.user_id AND j.kind='ADVANCE' AND r.kind='ADVANCE' AND j.amount=NEW.amount AND r.amount=NEW.amount
                ) THEN RAISE EXCEPTION 'Manual classification requires matching advance reversal'; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER deposit_receipt_change_guard BEFORE INSERT OR UPDATE OR DELETE ON deposit_receipt_type_changes
                FOR EACH ROW EXECUTE FUNCTION guard_deposit_receipt_type_change();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_receipt_type_changes');
        DB::statement('DROP FUNCTION IF EXISTS guard_deposit_receipt_type_change()');
    }
};
