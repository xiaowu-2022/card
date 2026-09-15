<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE wallet_transfers (
                id uuid PRIMARY KEY,
                tenant_id uuid NOT NULL REFERENCES tenants(id),
                sender_user_id uuid NOT NULL,
                recipient_user_id uuid NOT NULL,
                sender_wallet_id uuid NOT NULL,
                recipient_wallet_id uuid NOT NULL,
                recipient_account_id varchar(12) NOT NULL CHECK (recipient_account_id ~ '^[0-9]{12}$'),
                request_id uuid NOT NULL,
                asset_code varchar(12) NOT NULL,
                amount numeric(20,8) NOT NULL CHECK (amount > 0 AND amount = trunc(amount, 2)),
                ledger_entry_id uuid NOT NULL UNIQUE,
                created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (tenant_id, sender_user_id, request_id),
                UNIQUE (id, tenant_id),
                CHECK (sender_user_id <> recipient_user_id AND sender_wallet_id <> recipient_wallet_id),
                FOREIGN KEY (sender_wallet_id, tenant_id, sender_user_id, asset_code) REFERENCES wallets(id, tenant_id, user_id, asset_code),
                FOREIGN KEY (recipient_wallet_id, tenant_id, recipient_user_id, asset_code) REFERENCES wallets(id, tenant_id, user_id, asset_code),
                FOREIGN KEY (sender_user_id, tenant_id) REFERENCES users(id, tenant_id),
                FOREIGN KEY (recipient_user_id, tenant_id) REFERENCES users(id, tenant_id),
                FOREIGN KEY (ledger_entry_id, tenant_id, asset_code) REFERENCES ledger_entries(id, tenant_id, asset_code)
            );
            CREATE INDEX wallet_transfer_recipient_history ON wallet_transfers(tenant_id, recipient_user_id, created_at);
            CREATE TRIGGER wallet_transfer_immutable BEFORE UPDATE OR DELETE ON wallet_transfers
                FOR EACH ROW EXECUTE FUNCTION reject_ledger_history_mutation();
            CREATE OR REPLACE FUNCTION validate_wallet_transfer_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE transfer wallet_transfers%ROWTYPE; entry ledger_entries%ROWTYPE; matching integer; total integer;
            BEGIN
                IF TG_TABLE_NAME = 'wallet_transfers' THEN
                    SELECT * INTO transfer FROM wallet_transfers WHERE tenant_id = NEW.tenant_id AND id = NEW.id;
                    SELECT * INTO entry FROM ledger_entries WHERE tenant_id = transfer.tenant_id AND id = transfer.ledger_entry_id;
                ELSE
                    IF NEW.event_type <> 'WALLET_TRANSFER' THEN RETURN NULL; END IF;
                    SELECT * INTO entry FROM ledger_entries WHERE tenant_id = NEW.tenant_id AND id = NEW.id;
                    SELECT * INTO transfer FROM wallet_transfers WHERE tenant_id = NEW.tenant_id AND ledger_entry_id = NEW.id;
                END IF;
                IF transfer.id IS NULL OR entry.id IS NULL OR entry.sealed_at IS NULL
                    OR entry.event_type IS DISTINCT FROM 'WALLET_TRANSFER' OR entry.reference_type IS DISTINCT FROM 'WALLET_TRANSFER'
                    OR entry.reference_id IS DISTINCT FROM transfer.id OR entry.event_key IS DISTINCT FROM 'wallet_transfer:' || transfer.id::text
                    OR entry.asset_code IS DISTINCT FROM transfer.asset_code OR entry.reversal_of_entry_id IS NOT NULL THEN
                    RAISE EXCEPTION 'Wallet transfer requires an exact sealed receipt';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM users WHERE tenant_id = transfer.tenant_id AND id = transfer.recipient_user_id AND account_id = transfer.recipient_account_id) THEN
                    RAISE EXCEPTION 'Wallet transfer recipient mismatch';
                END IF;
                SELECT count(*) INTO total FROM ledger_postings WHERE tenant_id = transfer.tenant_id AND ledger_entry_id = entry.id;
                SELECT count(*) INTO matching FROM ledger_postings p JOIN ledger_accounts a ON a.id = p.ledger_account_id AND a.tenant_id = p.tenant_id
                    WHERE p.tenant_id = transfer.tenant_id AND p.ledger_entry_id = entry.id AND a.asset_code = transfer.asset_code AND a.account_type = 'USER_AVAILABLE'
                    AND ((a.user_id = transfer.sender_user_id AND a.wallet_id = transfer.sender_wallet_id AND p.delta = -transfer.amount)
                      OR (a.user_id = transfer.recipient_user_id AND a.wallet_id = transfer.recipient_wallet_id AND p.delta = transfer.amount));
                IF total <> 2 OR matching <> 2 THEN RAISE EXCEPTION 'Wallet transfer posting mismatch'; END IF;
                RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER wallet_transfer_evidence AFTER INSERT ON wallet_transfers DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION validate_wallet_transfer_evidence();
            CREATE CONSTRAINT TRIGGER wallet_transfer_entry_evidence AFTER INSERT OR UPDATE ON ledger_entries DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION validate_wallet_transfer_evidence();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Wallet transfers contain immutable financial history; use a forward migration.');
    }
};
