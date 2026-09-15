<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_cards', function (Blueprint $table): void {
            $table->unsignedBigInteger('refresh_generation')->default(0);
            $table->unique(['id', 'tenant_id', 'user_id'], 'managed_card_scope_unique');
        });
        Schema::create('card_management_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('card_id');
            $table->uuid('wallet_id');
            $table->uuid('request_id');
            $table->string('request_hash', 64);
            $table->string('kind', 24);
            $table->string('status', 16);
            $table->string('provider_card_id', 180);
            $table->string('provider_request_id', 180)->unique();
            $table->string('provider_transaction_id', 180)->nullable()->unique();
            $table->decimal('amount', 20, 8)->default(0);
            $table->decimal('debit_amount', 20, 8)->nullable();
            $table->decimal('arrival_amount', 20, 8)->nullable();
            $table->decimal('fee_amount', 20, 8)->nullable();
            $table->timestampTz('quote_expires_at')->nullable();
            $table->text('holder_changes_encrypted')->nullable();
            $table->uuid('hold_entry_id')->nullable();
            $table->uuid('settlement_entry_id')->nullable();
            $table->uuid('release_entry_id')->nullable();
            $table->timestampTz('provider_called_at')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id', 'request_id'], 'card_management_request_unique');
            $table->foreign(['card_id', 'tenant_id', 'user_id'], 'card_management_card_scope_fk')->references(['id', 'tenant_id', 'user_id'])->on('user_cards')->restrictOnDelete();
            $table->foreign('wallet_id')->references('id')->on('wallets')->restrictOnDelete();
            foreach (['hold_entry_id', 'settlement_entry_id', 'release_entry_id'] as $column) {
                $table->foreign($column)->references('id')->on('ledger_entries')->restrictOnDelete();
            }
            $table->index(['tenant_id', 'status', 'last_checked_at'], 'card_management_recovery_index');
        });
        DB::statement("ALTER TABLE card_management_orders ADD CONSTRAINT card_management_fields_check CHECK (
            kind IN ('LOAD','RETURN','FREEZE','UNFREEZE','CANCEL','HOLDER_UPDATE','CANCEL_RETURN')
            AND status IN ('QUOTING','QUOTED','PROCESSING','UNKNOWN','SUCCEEDED','FAILED','EXPIRED')
            AND amount >= 0 AND (debit_amount IS NULL OR debit_amount > 0)
            AND (arrival_amount IS NULL OR arrival_amount >= 0) AND (fee_amount IS NULL OR fee_amount >= 0)
            AND (kind = 'HOLDER_UPDATE' OR holder_changes_encrypted IS NULL)
            AND (kind = 'LOAD' OR hold_entry_id IS NULL AND release_entry_id IS NULL)
        )");
        DB::statement("CREATE UNIQUE INDEX card_management_outstanding_unique ON card_management_orders (card_id)
            WHERE status IN ('QUOTING','QUOTED','PROCESSING','UNKNOWN') AND kind <> 'CANCEL_RETURN'");

        Schema::create('card_provider_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('card_id')->nullable();
            $table->uuid('cardholder_id')->nullable();
            $table->uuid('issue_order_id')->nullable();
            $table->string('event_digest', 64)->unique();
            $table->string('category', 40);
            $table->string('event_type', 40);
            $table->string('transaction_id', 180)->nullable();
            $table->string('request_id', 180)->nullable();
            $table->string('status', 16)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['card_id', 'tenant_id', 'user_id'], 'card_event_scope_fk')->references(['id', 'tenant_id', 'user_id'])->on('user_cards')->restrictOnDelete();
            $table->foreign('cardholder_id')->references('id')->on('provider_cardholders')->restrictOnDelete();
            $table->foreign('issue_order_id')->references('id')->on('card_issue_orders')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'created_at'], 'card_event_pending_index');
        });
        DB::statement("ALTER TABLE card_provider_events ADD CONSTRAINT card_event_state_check CHECK (status IN ('PENDING','PROCESSED','RETRY'))");
        DB::statement('ALTER TABLE card_provider_events ADD CONSTRAINT card_event_resource_check CHECK (num_nonnulls(card_id,cardholder_id,issue_order_id) = 1)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_card_provider_event() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Provider event history is append-only'; END IF;
                IF TG_OP = 'UPDATE' AND (NEW.tenant_id,NEW.user_id,NEW.card_id,NEW.cardholder_id,NEW.issue_order_id,NEW.event_digest,NEW.category,NEW.event_type,NEW.transaction_id,NEW.request_id)
                    IS DISTINCT FROM (OLD.tenant_id,OLD.user_id,OLD.card_id,OLD.cardholder_id,OLD.issue_order_id,OLD.event_digest,OLD.category,OLD.event_type,OLD.transaction_id,OLD.request_id) THEN
                    RAISE EXCEPTION 'Provider event identity is immutable';
                END IF;
                IF NEW.cardholder_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM provider_cardholders WHERE id=NEW.cardholder_id AND tenant_id=NEW.tenant_id AND user_id=NEW.user_id) THEN RAISE EXCEPTION 'Provider event holder scope mismatch'; END IF;
                IF NEW.issue_order_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM card_issue_orders WHERE id=NEW.issue_order_id AND tenant_id=NEW.tenant_id AND user_id=NEW.user_id) THEN RAISE EXCEPTION 'Provider event issue scope mismatch'; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER card_event_protection BEFORE INSERT OR UPDATE OR DELETE ON card_provider_events FOR EACH ROW EXECUTE FUNCTION protect_card_provider_event();
            SQL);
        Schema::create('card_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('card_id');
            $table->string('provider_transaction_id', 180);
            $table->decimal('amount', 20, 8);
            $table->char('currency', 3);
            $table->string('type', 32);
            $table->string('state', 24);
            $table->string('occurred_at', 19);
            $table->string('merchant', 120)->nullable();
            $table->unsignedBigInteger('refresh_generation');
            $table->timestampsTz();
            $table->unique(['card_id', 'provider_transaction_id'], 'card_transaction_provider_unique');
            $table->foreign(['card_id', 'tenant_id', 'user_id'], 'card_transaction_scope_fk')->references(['id', 'tenant_id', 'user_id'])->on('user_cards')->restrictOnDelete();
            $table->index(['tenant_id', 'user_id', 'occurred_at'], 'card_transaction_user_index');
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_card_management_order() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Card management history cannot be deleted' USING ERRCODE = '55000'; END IF;
                IF (NEW.tenant_id, NEW.user_id, NEW.card_id, NEW.wallet_id, NEW.request_id, NEW.request_hash, NEW.kind, NEW.amount, NEW.provider_card_id, NEW.provider_request_id, NEW.holder_changes_encrypted)
                   IS DISTINCT FROM (OLD.tenant_id, OLD.user_id, OLD.card_id, OLD.wallet_id, OLD.request_id, OLD.request_hash, OLD.kind, OLD.amount, OLD.provider_card_id, OLD.provider_request_id, OLD.holder_changes_encrypted) THEN
                    RAISE EXCEPTION 'Card management identity is immutable' USING ERRCODE = '55000';
                END IF;
                IF OLD.provider_transaction_id IS NOT NULL AND NEW.provider_transaction_id IS DISTINCT FROM OLD.provider_transaction_id THEN
                    RAISE EXCEPTION 'Provider settlement identity is immutable' USING ERRCODE = '55000';
                END IF;
                IF OLD.kind = 'LOAD' AND OLD.status <> 'QUOTING' AND (NEW.debit_amount, NEW.arrival_amount, NEW.fee_amount, NEW.quote_expires_at)
                    IS DISTINCT FROM (OLD.debit_amount, OLD.arrival_amount, OLD.fee_amount, OLD.quote_expires_at) THEN
                    RAISE EXCEPTION 'Confirmed quotation is immutable' USING ERRCODE = '55000';
                END IF;
                IF OLD.status IN ('SUCCEEDED','FAILED','EXPIRED') AND (NEW.status, NEW.debit_amount, NEW.arrival_amount, NEW.fee_amount, NEW.hold_entry_id, NEW.settlement_entry_id, NEW.release_entry_id, NEW.provider_transaction_id)
                    IS DISTINCT FROM (OLD.status, OLD.debit_amount, OLD.arrival_amount, OLD.fee_amount, OLD.hold_entry_id, OLD.settlement_entry_id, OLD.release_entry_id, OLD.provider_transaction_id) THEN
                    RAISE EXCEPTION 'Terminal Card operation is immutable' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER card_management_immutable BEFORE UPDATE OR DELETE ON card_management_orders
                FOR EACH ROW EXECUTE FUNCTION protect_card_management_order();

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
                    (o.debit_amount IS NULL OR o.fee_amount IS NULL OR o.arrival_amount IS NULL OR o.debit_amount <> o.arrival_amount + o.fee_amount) THEN
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
            CREATE CONSTRAINT TRIGGER card_management_accounting AFTER INSERT OR UPDATE ON card_management_orders
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_card_management_accounting();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('card_transactions');
        Schema::dropIfExists('card_provider_events');
        DB::statement('DROP FUNCTION IF EXISTS protect_card_provider_event()');
        Schema::dropIfExists('card_management_orders');
        DB::statement('DROP FUNCTION IF EXISTS check_card_management_accounting()');
        DB::statement('DROP FUNCTION IF EXISTS protect_card_management_order()');
        Schema::table('user_cards', function (Blueprint $table): void {
            $table->dropUnique('managed_card_scope_unique');
            $table->dropColumn('refresh_generation');
        });
    }
};
