<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_management_orders', fn (Blueprint $t) => $t->decimal('manual_funding_amount', 20, 8)->default(0));
        DB::statement("ALTER TABLE card_management_orders ADD CONSTRAINT card_manual_funding_valid CHECK (manual_funding_amount >= 0 AND (manual_funding_amount = 0 OR (kind = 'LOAD' AND overflow_amount IS NOT NULL AND manual_funding_amount = overflow_amount)))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_card_manual_funding() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.manual_funding_amount IS DISTINCT FROM OLD.manual_funding_amount THEN
                    RAISE EXCEPTION 'Manual funding snapshot is immutable' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER card_manual_funding_immutable BEFORE UPDATE ON card_management_orders FOR EACH ROW EXECUTE FUNCTION guard_card_manual_funding();
            CREATE OR REPLACE FUNCTION check_card_management_accounting() RETURNS trigger AS $$
            DECLARE o card_management_orders%ROWTYPE; e uuid; amt numeric; src text; dst text; suffix text; n integer; src_total numeric; dst_total numeric;
            BEGIN
                SELECT * INTO o FROM card_management_orders WHERE id = NEW.id;
                IF NOT EXISTS (SELECT 1 FROM wallets WHERE id = o.wallet_id AND tenant_id = o.tenant_id AND user_id = o.user_id AND asset_code = 'USDT') THEN
                    RAISE EXCEPTION 'Card wallet scope mismatch' USING ERRCODE = '23514';
                END IF;
                IF o.kind = 'LOAD' THEN
                    IF o.status IN ('PROCESSING','UNKNOWN','SUCCEEDED') AND o.hold_entry_id IS NULL THEN
                        RAISE EXCEPTION 'Card reload requires a hold' USING ERRCODE = '23514';
                    END IF;
                    IF (o.status = 'SUCCEEDED' AND o.settlement_entry_id IS NULL) OR
                       (o.status = 'FAILED' AND o.hold_entry_id IS NOT NULL AND o.release_entry_id IS NULL) OR
                       (o.status <> 'SUCCEEDED' AND o.settlement_entry_id IS NOT NULL) OR
                       (o.status <> 'FAILED' AND o.release_entry_id IS NOT NULL) THEN
                        RAISE EXCEPTION 'Card reload terminal accounting mismatch' USING ERRCODE = '23514';
                    END IF;
                ELSIF o.kind IN ('RETURN','CANCEL_RETURN') THEN
                    IF o.status = 'SUCCEEDED' AND (o.provider_transaction_id IS NULL OR o.arrival_amount IS NULL OR (o.arrival_amount > 0 AND o.settlement_entry_id IS NULL)) THEN
                        RAISE EXCEPTION 'Card return requires verified settlement' USING ERRCODE = '23514';
                    END IF;
                    IF o.status <> 'SUCCEEDED' AND o.settlement_entry_id IS NOT NULL THEN
                        RAISE EXCEPTION 'Card return cannot credit before success' USING ERRCODE = '23514';
                    END IF;
                ELSIF o.settlement_entry_id IS NOT NULL THEN
                    RAISE EXCEPTION 'Nonfinancial card operation cannot settle money' USING ERRCODE = '23514';
                END IF;
                IF o.status = 'SUCCEEDED' AND o.kind IN ('LOAD','RETURN','CANCEL_RETURN') AND
                    (o.debit_amount IS NULL OR o.fee_amount IS NULL OR o.arrival_amount IS NULL OR o.debit_amount <> o.arrival_amount + o.fee_amount + CASE WHEN o.kind = 'LOAD' THEN o.manual_funding_amount ELSE 0 END) THEN
                    RAISE EXCEPTION 'Card monetary evidence mismatch' USING ERRCODE = '23514';
                END IF;
                FOREACH suffix IN ARRAY ARRAY['hold','settle','release'] LOOP
                    e := CASE suffix WHEN 'hold' THEN o.hold_entry_id WHEN 'settle' THEN o.settlement_entry_id ELSE o.release_entry_id END;
                    IF e IS NULL THEN CONTINUE; END IF;
                    amt := CASE WHEN suffix = 'settle' AND o.kind IN ('RETURN','CANCEL_RETURN') THEN o.arrival_amount ELSE o.debit_amount END;
                    src := CASE WHEN suffix = 'hold' THEN 'USER_AVAILABLE' WHEN o.kind IN ('RETURN','CANCEL_RETURN') THEN 'TENANT_CARD_FUNDING_CLEARING' ELSE 'USER_CARD_FUNDING_HOLD' END;
                    dst := CASE WHEN suffix = 'release' OR o.kind IN ('RETURN','CANCEL_RETURN') THEN 'USER_AVAILABLE' WHEN suffix = 'hold' THEN 'USER_CARD_FUNDING_HOLD' ELSE 'TENANT_CARD_FUNDING_CLEARING' END;
                    IF NOT EXISTS (SELECT 1 FROM ledger_entries WHERE id = e AND tenant_id = o.tenant_id AND asset_code = 'USDT' AND sealed_at IS NOT NULL
                        AND event_key = 'card_management:' || o.id || ':' || suffix AND reference_id = o.id AND reference_type = 'CARD_MANAGEMENT_ORDER') THEN
                        RAISE EXCEPTION 'Card accounting evidence missing' USING ERRCODE = '23514';
                    END IF;
                    SELECT count(*), coalesce(sum(CASE WHEN a.account_type = src THEN p.delta ELSE 0 END),0), coalesce(sum(CASE WHEN a.account_type = dst THEN p.delta ELSE 0 END),0)
                        INTO n, src_total, dst_total FROM ledger_postings p JOIN ledger_accounts a ON a.id = p.ledger_account_id
                        WHERE p.ledger_entry_id = e AND a.tenant_id = o.tenant_id AND a.asset_code = 'USDT'
                        AND (a.wallet_id = o.wallet_id AND a.user_id = o.user_id OR a.wallet_id IS NULL AND a.user_id IS NULL);
                    IF n <> 2 OR src_total <> -amt OR dst_total <> amt THEN
                        RAISE EXCEPTION 'Card accounting path mismatch' USING ERRCODE = '23514';
                    END IF;
                END LOOP;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Manual funding snapshots cannot be dropped after financial use.');
    }
};
