<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_deposit_refund_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE r security_deposit_refund_requests%ROWTYPE; e ledger_entries%ROWTYPE; n integer; c integer;
            BEGIN
                SELECT * INTO r FROM security_deposit_refund_requests WHERE tenant_id=NEW.tenant_id AND id=NEW.id;
                IF r.status<>'COMPLETED' THEN RETURN NULL; END IF;
                SELECT * INTO e FROM ledger_entries WHERE tenant_id=r.tenant_id AND id=r.ledger_entry_id;
                SELECT count(*) INTO n FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id AND a.tenant_id=p.tenant_id
                WHERE p.tenant_id=r.tenant_id AND p.ledger_entry_id=e.id AND a.wallet_id=r.wallet_id AND a.user_id=r.user_id
                AND ((a.account_type='USER_SECURITY_DEPOSIT' AND p.delta=-r.amount) OR (a.account_type='USER_AVAILABLE' AND p.delta=r.amount));
                SELECT count(*) INTO c FROM ledger_postings WHERE tenant_id=r.tenant_id AND ledger_entry_id=e.id;
                IF e.id IS NULL OR e.sealed_at IS NULL OR e.asset_code IS DISTINCT FROM r.asset_code
                    OR e.event_key IS DISTINCT FROM 'deposit_refund:'||r.id::text
                    OR e.event_type IS DISTINCT FROM 'SECURITY_DEPOSIT_REFUND'
                    OR e.reference_type IS DISTINCT FROM 'SECURITY_DEPOSIT_REFUND'
                    OR e.reference_id IS DISTINCT FROM r.id OR n<>2 OR c<>2 THEN
                    RAISE EXCEPTION 'Refund requires exact sealed financial evidence';
                END IF;
                RETURN NULL;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Do not weaken immutable financial evidence; use a forward migration.');
    }
};
