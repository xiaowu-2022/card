<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $definition = DB::selectOne("SELECT pg_get_expr(conbin, conrelid) AS definition FROM pg_constraint WHERE conrelid = 'ledger_accounts'::regclass AND conname = 'ledger_accounts_type_check'")->definition;
        DB::statement('ALTER TABLE ledger_accounts DROP CONSTRAINT ledger_accounts_type_check');
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_check CHECK (($definition) OR account_type = 'USER_CARD_OVERFLOW')");
        Schema::table('ledger_accounts', fn (Blueprint $t) => $t->foreignUuid('card_id')->nullable()->constrained('user_cards'));
        DB::statement('ALTER TABLE ledger_accounts DROP CONSTRAINT ledger_accounts_wallet_id_account_type_unique');
        DB::statement('CREATE UNIQUE INDEX ledger_accounts_wallet_type_unique ON ledger_accounts(wallet_id,account_type) WHERE card_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX ledger_accounts_card_type_unique ON ledger_accounts(card_id,account_type) WHERE card_id IS NOT NULL');
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_card_account_check CHECK ((account_type = 'USER_CARD_OVERFLOW' AND card_id IS NOT NULL AND asset_code = 'USDT') OR (account_type <> 'USER_CARD_OVERFLOW' AND card_id IS NULL))");
        Schema::create('card_overflow_movements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->foreignUuid('user_id')->constrained('users');
            $t->foreignUuid('card_id')->constrained('user_cards');
            $t->foreignUuid('order_id')->nullable()->unique()->constrained('card_management_orders');
            $t->string('kind', 16);
            $t->decimal('amount', 20, 8);
            $t->uuid('request_id');
            $t->foreignUuid('ledger_entry_id')->unique()->constrained('ledger_entries');
            $t->foreignUuid('actor_id')->nullable()->constrained('admin_users');
            $t->string('note', 500)->nullable();
            $t->timestampTz('created_at');
            $t->unique(['tenant_id', 'card_id', 'request_id']);
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_card_overflow_account() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'UPDATE' AND NEW.card_id IS DISTINCT FROM OLD.card_id THEN RAISE EXCEPTION 'Card account identity is immutable'; END IF;
 IF NEW.card_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM user_cards WHERE id=NEW.card_id AND tenant_id=NEW.tenant_id AND user_id=NEW.user_id) THEN RAISE EXCEPTION 'Card account ownership mismatch'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER card_overflow_account_guard BEFORE INSERT OR UPDATE ON ledger_accounts FOR EACH ROW EXECUTE FUNCTION guard_card_overflow_account();
ALTER TABLE card_overflow_movements ADD CHECK (amount>0 AND ((kind='FUNDING' AND order_id IS NOT NULL AND actor_id IS NULL) OR (kind='SPEND' AND order_id IS NULL AND actor_id IS NOT NULL)));
CREATE TRIGGER card_overflow_history_guard BEFORE UPDATE OR DELETE ON card_overflow_movements FOR EACH ROW EXECUTE FUNCTION reject_ledger_history_mutation();
CREATE OR REPLACE FUNCTION check_card_overflow_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE m card_overflow_movements%ROWTYPE; e ledger_entries%ROWTYPE; n integer; credit numeric; clearing numeric; expected numeric;
BEGIN
 IF TG_TABLE_NAME='ledger_entries' THEN
  IF NOT EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id WHERE p.ledger_entry_id=NEW.id AND a.account_type='USER_CARD_OVERFLOW') THEN RETURN NULL; END IF;
  SELECT * INTO m FROM card_overflow_movements WHERE ledger_entry_id=NEW.id;
 ELSE SELECT * INTO m FROM card_overflow_movements WHERE id=NEW.id;
 END IF;
 IF m.id IS NULL THEN RAISE EXCEPTION 'Missing overflow business evidence'; END IF;
 SELECT * INTO e FROM ledger_entries WHERE id=m.ledger_entry_id;
 IF e.id IS NULL OR e.sealed_at IS NULL OR (e.tenant_id,e.asset_code,e.reference_type,e.reference_id,e.event_type,e.event_key) IS DISTINCT FROM (m.tenant_id,'USDT','CARD_OVERFLOW_MOVEMENT',m.id,'CARD_OVERFLOW_'||m.kind,'card_overflow:'||m.id) THEN RAISE EXCEPTION 'Invalid overflow ledger evidence'; END IF;
 IF NOT EXISTS (SELECT 1 FROM user_cards WHERE id=m.card_id AND tenant_id=m.tenant_id AND user_id=m.user_id) THEN RAISE EXCEPTION 'Overflow card ownership mismatch'; END IF;
 IF m.kind='FUNDING' AND NOT EXISTS (SELECT 1 FROM card_management_orders WHERE id=m.order_id AND tenant_id=m.tenant_id AND user_id=m.user_id AND card_id=m.card_id AND kind='LOAD' AND status='SUCCEEDED' AND settlement_entry_id IS NOT NULL AND manual_funding_amount=m.amount) THEN RAISE EXCEPTION 'Overflow funding requires a settled recharge'; END IF;
 expected := CASE WHEN m.kind='FUNDING' THEN m.amount ELSE -m.amount END;
 SELECT count(*), coalesce(sum(CASE WHEN a.account_type='USER_CARD_OVERFLOW' AND a.card_id=m.card_id AND a.user_id=m.user_id THEN p.delta ELSE 0 END),0),coalesce(sum(CASE WHEN a.account_type='TENANT_CARD_FUNDING_CLEARING' AND a.card_id IS NULL AND a.user_id IS NULL AND a.wallet_id IS NULL THEN p.delta ELSE 0 END),0)
 INTO n,credit,clearing FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id WHERE p.ledger_entry_id=e.id AND a.tenant_id=m.tenant_id AND a.asset_code='USDT';
 IF n<>2 OR credit<>expected OR clearing<>-expected THEN RAISE EXCEPTION 'Overflow accounting path mismatch'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER card_overflow_evidence AFTER INSERT ON card_overflow_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_card_overflow_evidence();
CREATE CONSTRAINT TRIGGER card_overflow_entry_evidence AFTER INSERT OR UPDATE ON ledger_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_card_overflow_evidence();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Card overflow accounting history cannot be dropped.');
    }
};
