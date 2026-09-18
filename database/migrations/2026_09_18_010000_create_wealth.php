<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the existing allowlists without changing any balances or historical entries.
        foreach (['ledger_accounts_type_check', 'ledger_accounts_negative_policy_check'] as $name) {
            $definition = DB::selectOne('SELECT pg_get_expr(conbin, conrelid) AS definition FROM pg_constraint WHERE conrelid = ?::regclass AND conname = ?', ['ledger_accounts', $name])->definition;
            $extra = $name === 'ledger_accounts_type_check' ? "account_type IN ('USER_WEALTH_PRINCIPAL','TENANT_WEALTH_INTEREST_CLEARING')" : "account_type = 'TENANT_WEALTH_INTEREST_CLEARING'";
            DB::statement("ALTER TABLE ledger_accounts DROP CONSTRAINT {$name}");
            $expression = $definition;
            DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT {$name} CHECK (($expression) OR ($extra))");
        }
        Schema::create('wealth_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->string('asset_code', 12);
            $t->decimal('minimum', 38, 18)->nullable();
            $t->jsonb('products');
            $t->uuid('revision');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'asset_code']);
        });
        Schema::create('wealth_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->foreignUuid('user_id')->constrained('users');
            $t->foreignUuid('wallet_id')->constrained('wallets');
            $t->string('asset_code', 12);
            $t->decimal('principal', 38, 18);
            $t->unsignedInteger('months');
            $t->decimal('annual_rate', 12, 8);
            $t->uuid('config_revision');
            $t->string('timezone');
            $t->jsonb('schedule');
            $t->timestampTz('started_at');
            $t->timestampTz('matures_at');
            $t->string('status', 16)->default('ACTIVE');
            $t->uuid('request_id');
            $t->foreignUuid('deposit_entry_id')->unique()->constrained('ledger_entries');
            $t->foreignUuid('close_entry_id')->nullable()->unique()->constrained('ledger_entries');
            $t->uuid('cancel_request_id')->nullable();
            $t->decimal('returned', 38, 18)->nullable();
            $t->decimal('clawback', 38, 18)->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'user_id', 'request_id']);
            $t->unique(['tenant_id', 'user_id', 'cancel_request_id']);
            $t->index(['status', 'matures_at']);
        });
        Schema::create('wealth_installments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->foreignUuid('order_id')->constrained('wealth_orders');
            $t->unsignedInteger('month');
            $t->timestampTz('due_at');
            $t->decimal('amount', 38, 18);
            $t->foreignUuid('ledger_entry_id')->nullable()->unique()->constrained('ledger_entries');
            $t->timestampTz('settled_at')->nullable();
            $t->unique(['order_id', 'month']);
            $t->index(['settled_at', 'due_at']);
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE wealth_settings ADD CHECK (asset_code IN ('USDT','USDC','ETH','BTC') AND (minimum IS NULL OR minimum > 0));
ALTER TABLE wealth_orders ADD CHECK (asset_code IN ('USDT','USDC','ETH','BTC') AND principal > 0 AND months IN (1,3,6,12,24,36,60) AND annual_rate >= 0 AND annual_rate * months < 1200 AND matures_at > started_at AND jsonb_array_length(schedule) = months);
ALTER TABLE wealth_orders ADD CHECK ((status = 'ACTIVE' AND close_entry_id IS NULL AND returned IS NULL AND clawback IS NULL AND closed_at IS NULL AND cancel_request_id IS NULL) OR (status IN ('CANCELLED','MATURED') AND close_entry_id IS NOT NULL AND returned > 0 AND clawback >= 0 AND returned + clawback = principal AND closed_at IS NOT NULL AND ((status = 'CANCELLED' AND cancel_request_id IS NOT NULL AND closed_at < matures_at) OR (status = 'MATURED' AND cancel_request_id IS NULL AND clawback = 0 AND closed_at >= matures_at))));
ALTER TABLE wealth_installments ADD CHECK (amount >= 0 AND ((settled_at IS NULL AND ledger_entry_id IS NULL) OR (settled_at IS NOT NULL AND ((amount = 0 AND ledger_entry_id IS NULL) OR (amount > 0 AND ledger_entry_id IS NOT NULL)))));
CREATE OR REPLACE FUNCTION protect_wealth_records() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Wealth history is immutable'; END IF;
 IF TG_TABLE_NAME = 'wealth_orders' THEN
  IF TG_OP = 'UPDATE' AND ((to_jsonb(NEW) - ARRAY['status','close_entry_id','cancel_request_id','returned','clawback','closed_at','updated_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','close_entry_id','cancel_request_id','returned','clawback','closed_at','updated_at']) OR OLD.status <> 'ACTIVE') THEN RAISE EXCEPTION 'Wealth contract is immutable'; END IF;
  IF NOT EXISTS (SELECT 1 FROM wallets WHERE id=NEW.wallet_id AND tenant_id=NEW.tenant_id AND user_id=NEW.user_id AND asset_code=NEW.asset_code) THEN RAISE EXCEPTION 'Wealth wallet scope mismatch'; END IF;
 ELSE
  IF TG_OP = 'UPDATE' AND ((to_jsonb(NEW) - ARRAY['ledger_entry_id','settled_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['ledger_entry_id','settled_at']) OR OLD.settled_at IS NOT NULL) THEN RAISE EXCEPTION 'Wealth installment is immutable'; END IF;
  IF NOT EXISTS (SELECT 1 FROM wealth_orders o WHERE o.id=NEW.order_id AND o.tenant_id=NEW.tenant_id AND o.status='ACTIVE' AND (o.schedule->(NEW.month-1)->>'amount')::numeric=NEW.amount AND (o.schedule->(NEW.month-1)->>'due_at')::timestamptz=NEW.due_at) THEN RAISE EXCEPTION 'Wealth schedule mismatch'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER wealth_order_protection BEFORE INSERT OR UPDATE OR DELETE ON wealth_orders FOR EACH ROW EXECUTE FUNCTION protect_wealth_records();
CREATE TRIGGER wealth_installment_protection BEFORE INSERT OR UPDATE OR DELETE ON wealth_installments FOR EACH ROW EXECUTE FUNCTION protect_wealth_records();
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION wealth_check_entry(tid uuid, oid uuid, wid uuid, uid uuid, asset text, eid uuid, event text, expected jsonb) RETURNS void LANGUAGE plpgsql AS $$
DECLARE actual jsonb;
BEGIN
 IF NOT EXISTS (SELECT 1 FROM ledger_entries WHERE id=eid AND tenant_id=tid AND asset_code=asset AND event_type=event AND reference_type='WEALTH_ORDER' AND reference_id=oid AND sealed_at IS NOT NULL) THEN RAISE EXCEPTION 'Wealth ledger entry mismatch'; END IF;
 IF EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id WHERE p.ledger_entry_id=eid AND (p.tenant_id<>tid OR a.tenant_id<>tid OR a.asset_code<>asset OR (a.account_type LIKE 'USER_%' AND (a.wallet_id IS DISTINCT FROM wid OR a.user_id IS DISTINCT FROM uid)) OR (a.account_type LIKE 'TENANT_%' AND (a.wallet_id IS NOT NULL OR a.user_id IS NOT NULL)))) THEN RAISE EXCEPTION 'Wealth posting ownership mismatch'; END IF;
 SELECT jsonb_object_agg(account_type, delta) INTO actual FROM (SELECT a.account_type, sum(p.delta) AS delta FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id WHERE p.ledger_entry_id=eid GROUP BY a.account_type) q;
 IF actual IS DISTINCT FROM expected THEN RAISE EXCEPTION 'Wealth posting economics mismatch'; END IF;
END $$;
CREATE OR REPLACE FUNCTION validate_wealth_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE oid uuid; o wealth_orders%ROWTYPE; r record; paid numeric; expected jsonb; cumulative numeric:=0; prior numeric:=0; scale integer;
BEGIN
 IF TG_TABLE_NAME='ledger_entries' THEN
  IF NEW.event_type NOT IN ('WEALTH_DEPOSIT','WEALTH_INTEREST','WEALTH_CANCEL','WEALTH_MATURITY') THEN
   IF EXISTS (SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id WHERE p.ledger_entry_id=NEW.id AND a.account_type IN ('USER_WEALTH_PRINCIPAL','TENANT_WEALTH_INTEREST_CLEARING')) THEN RAISE EXCEPTION 'Wealth accounts require wealth business evidence'; END IF;
   RETURN NEW;
  END IF;
  oid:=NEW.reference_id;
 ELSIF TG_TABLE_NAME='wealth_orders' THEN oid:=NEW.id;
 ELSE oid:=NEW.order_id; END IF;
 SELECT * INTO o FROM wealth_orders WHERE id=oid;
 IF NOT FOUND THEN RAISE EXCEPTION 'Wealth order evidence missing'; END IF;
 PERFORM wealth_check_entry(o.tenant_id,o.id,o.wallet_id,o.user_id,o.asset_code,o.deposit_entry_id,'WEALTH_DEPOSIT',jsonb_build_object('USER_AVAILABLE',-o.principal,'USER_WEALTH_PRINCIPAL',o.principal));
 IF (SELECT count(*) FROM wealth_installments WHERE order_id=o.id AND tenant_id=o.tenant_id)<>o.months THEN RAISE EXCEPTION 'Wealth installment evidence missing'; END IF;
 scale:=CASE o.asset_code WHEN 'ETH' THEN 18 WHEN 'USDC' THEN 6 ELSE 8 END;
 FOR r IN SELECT * FROM wealth_installments WHERE order_id=o.id ORDER BY month LOOP
  cumulative:=trunc(o.principal*o.annual_rate*r.month/1200,scale);
  IF r.amount<>cumulative-prior OR r.month<1 OR r.month>o.months OR r.due_at<>(o.schedule->(r.month-1)->>'due_at')::timestamptz OR r.amount<>(o.schedule->(r.month-1)->>'amount')::numeric THEN RAISE EXCEPTION 'Wealth interest economics mismatch'; END IF;
  prior:=cumulative;
  IF r.settled_at IS NOT NULL THEN
   IF r.settled_at<r.due_at OR (o.status='CANCELLED' AND r.settled_at>o.closed_at) THEN RAISE EXCEPTION 'Wealth interest timing mismatch'; END IF;
   IF r.amount>0 THEN PERFORM wealth_check_entry(o.tenant_id,o.id,o.wallet_id,o.user_id,o.asset_code,r.ledger_entry_id,'WEALTH_INTEREST',jsonb_build_object('TENANT_WEALTH_INTEREST_CLEARING',-r.amount,'USER_AVAILABLE',r.amount)); END IF;
  ELSIF o.status='MATURED' THEN RAISE EXCEPTION 'Matured wealth interest missing'; END IF;
 END LOOP;
 SELECT coalesce(sum(amount),0) INTO paid FROM wealth_installments WHERE order_id=o.id AND settled_at IS NOT NULL;
 IF o.status='CANCELLED' THEN
  IF o.clawback<>paid THEN RAISE EXCEPTION 'Wealth clawback mismatch'; END IF;
  expected:=jsonb_build_object('USER_WEALTH_PRINCIPAL',-o.principal,'USER_AVAILABLE',o.returned);
  IF paid>0 THEN expected:=expected||jsonb_build_object('TENANT_WEALTH_INTEREST_CLEARING',paid); END IF;
  PERFORM wealth_check_entry(o.tenant_id,o.id,o.wallet_id,o.user_id,o.asset_code,o.close_entry_id,'WEALTH_CANCEL',expected);
 ELSIF o.status='MATURED' THEN
  PERFORM wealth_check_entry(o.tenant_id,o.id,o.wallet_id,o.user_id,o.asset_code,o.close_entry_id,'WEALTH_MATURITY',jsonb_build_object('USER_WEALTH_PRINCIPAL',-o.principal,'USER_AVAILABLE',o.principal));
 END IF;
 IF TG_TABLE_NAME='ledger_entries' AND NOT (NEW.id=o.deposit_entry_id OR NEW.id IS NOT DISTINCT FROM o.close_entry_id OR EXISTS(SELECT 1 FROM wealth_installments WHERE order_id=o.id AND ledger_entry_id=NEW.id)) THEN RAISE EXCEPTION 'Unlinked wealth ledger entry'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER wealth_order_evidence AFTER INSERT OR UPDATE ON wealth_orders DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_wealth_evidence();
CREATE CONSTRAINT TRIGGER wealth_installment_evidence AFTER INSERT OR UPDATE ON wealth_installments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_wealth_evidence();
CREATE CONSTRAINT TRIGGER wealth_entry_evidence AFTER INSERT OR UPDATE ON ledger_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_wealth_evidence();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Wealth financial history must not be dropped.');
    }
};
