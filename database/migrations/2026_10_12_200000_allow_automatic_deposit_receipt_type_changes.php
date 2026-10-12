<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->guard(false);
    }

    public function down(): void
    {
        // Never make retained automatic-receipt corrections incompatible with their guard.
        foreach (['primary' => 'wallet_topup_orders', 'asset' => 'asset_deposit_orders'] as $source => $table) {
            if (DB::table('deposit_receipt_type_changes as c')->join($table.' as o', fn ($j) => $j
                ->on('o.id', '=', 'c.order_id')->on('o.tenant_id', '=', 'c.tenant_id'))
                ->where('c.source', $source)->whereNull('o.manual_confirmed_at')->exists()) {
                throw new RuntimeException('Automatic receipt classification history prevents rollback.');
            }
        }
        $this->guard(true);
    }

    private function guard(bool $manualOnly): void
    {
        $sql = <<<'SQL'
            CREATE OR REPLACE FUNCTION guard_deposit_receipt_type_change() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE facts jsonb; previous deposit_receipt_type_changes%ROWTYPE; expected_type text; expected_journal uuid;
            BEGIN
                IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Deposit receipt type changes are immutable'; END IF;
                IF NEW.source='primary' THEN
                    SELECT to_jsonb(o) INTO facts FROM wallet_topup_orders o WHERE id=NEW.order_id AND tenant_id=NEW.tenant_id FOR UPDATE;
                ELSE
                    SELECT to_jsonb(o) INTO facts FROM asset_deposit_orders o WHERE id=NEW.order_id AND tenant_id=NEW.tenant_id FOR UPDATE;
                END IF;
                IF facts IS NULL OR facts->>'status'<>'CREDITED' __MANUAL_ONLY__ OR facts->>'ledger_entry_id' IS NULL
                    OR facts->>'user_id' IS DISTINCT FROM NEW.user_id::text OR facts->>'asset_code' IS DISTINCT FROM NEW.asset_code
                    OR COALESCE(facts->>'actual_received_amount', facts->>'amount')::numeric IS DISTINCT FROM NEW.amount
                THEN RAISE EXCEPTION 'Only settled receipts may change classification'; END IF;
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
            SQL;
        DB::unprepared(str_replace('__MANUAL_ONLY__', $manualOnly ? "OR facts->>'manual_confirmed_at' IS NULL" : '', $sql));
    }
};
