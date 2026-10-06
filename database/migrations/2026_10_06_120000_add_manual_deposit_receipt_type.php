<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->assetOrderGuard(true);
        DB::statement('ALTER TABLE partner_journal_entries ADD CONSTRAINT journal_receipt_identity UNIQUE(id, tenant_id)');
        foreach (['wallet_topup_orders', 'asset_deposit_orders'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN manual_receipt_type varchar(10) NULL, ADD COLUMN advance_journal_id uuid NULL UNIQUE,
                ADD CONSTRAINT {$table}_receipt_journal FOREIGN KEY (advance_journal_id, tenant_id) REFERENCES partner_journal_entries(id, tenant_id),
                ADD CONSTRAINT {$table}_receipt_type CHECK (
                    (manual_receipt_type IS NULL AND advance_journal_id IS NULL) OR
                    (manual_confirmed_at IS NOT NULL AND manual_receipt_type IS NOT NULL AND
                        ((manual_receipt_type='ACTUAL' AND advance_journal_id IS NULL) OR
                         (manual_receipt_type='ADVANCE' AND advance_journal_id IS NOT NULL AND asset_code='USDT'))))");
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_manual_receipt_type() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
                IF (OLD.manual_confirmed_at IS NOT NULL OR OLD.status='CREDITED') AND
                    ROW(NEW.manual_receipt_type, NEW.advance_journal_id) IS DISTINCT FROM ROW(OLD.manual_receipt_type, OLD.advance_journal_id)
                THEN RAISE EXCEPTION 'Manual receipt classification is immutable'; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER manual_receipt_immutable BEFORE UPDATE ON wallet_topup_orders FOR EACH ROW EXECUTE FUNCTION protect_manual_receipt_type();
            CREATE TRIGGER manual_receipt_immutable BEFORE UPDATE ON asset_deposit_orders FOR EACH ROW EXECUTE FUNCTION protect_manual_receipt_type();
            SQL);
    }

    public function down(): void
    {
        $this->assetOrderGuard(false);
        foreach (['wallet_topup_orders', 'asset_deposit_orders'] as $table) {
            DB::statement("DROP TRIGGER manual_receipt_immutable ON {$table}");
            DB::statement("ALTER TABLE {$table} DROP COLUMN manual_receipt_type, DROP COLUMN advance_journal_id");
        }
        DB::statement('DROP FUNCTION protect_manual_receipt_type()');
        DB::statement('ALTER TABLE partner_journal_entries DROP CONSTRAINT journal_receipt_identity');
    }

    private function assetOrderGuard(bool $withReceipt): void
    {
        $sql = <<<'SQL'
            CREATE OR REPLACE FUNCTION protect_asset_order() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE field text;
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'asset order history is immutable'; END IF;
                IF TG_TABLE_NAME = 'asset_exchange_orders' THEN
                    IF (to_jsonb(NEW) - ARRAY['status','source_entry_id','target_entry_id','completed_at','updated_at']) IS DISTINCT FROM
                       (to_jsonb(OLD) - ARRAY['status','source_entry_id','target_entry_id','completed_at','updated_at'])
                       OR OLD.status = 'COMPLETED' THEN RAISE EXCEPTION 'exchange economics and completion are immutable'; END IF;
                ELSE
                    IF (to_jsonb(NEW) - ARRAY['status','chain_event_id','ledger_entry_id','updated_at','manual_confirmed_by','manual_confirmed_at','manual_request_id','hold_entry_id','submitted_tx_hash','reviewed_by','reviewed_at']) IS DISTINCT FROM
                       (to_jsonb(OLD) - ARRAY['status','chain_event_id','ledger_entry_id','updated_at','manual_confirmed_by','manual_confirmed_at','manual_request_id','hold_entry_id','submitted_tx_hash','reviewed_by','reviewed_at'])
                       OR OLD.status IN ('CREDITED','COMPLETED','CANCELLED','REJECTED') THEN RAISE EXCEPTION 'asset order identity and terminal facts are immutable'; END IF;
                END IF;
                FOREACH field IN ARRAY ARRAY['submitted_tx_hash','hold_entry_id','reviewed_by','reviewed_at'] LOOP
                    IF to_jsonb(OLD)->>field IS NOT NULL AND (to_jsonb(NEW)->>field) IS DISTINCT FROM (to_jsonb(OLD)->>field) THEN
                        RAISE EXCEPTION 'recorded financial provenance is immutable';
                    END IF;
                END LOOP;
                RETURN NEW;
            END; $$;
            SQL;
        if ($withReceipt) {
            $sql = str_replace("'manual_request_id',", "'manual_request_id','manual_receipt_type','advance_journal_id',", $sql);
        }
        DB::unprepared($sql);
    }
};
