<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE paid_promotion_orders IN ACCESS EXCLUSIVE MODE');
        Schema::table('paid_promotion_orders', function (Blueprint $t): void {
            $t->decimal('deposit_applied', 20, 8)->default(0);
            $t->decimal('deposit_snapshot', 20, 8)->default(0);
            $t->decimal('settlement_total', 20, 8)->nullable();
        });
        // Add accounting dimensions without changing any original amount or Ledger row.
        DB::statement('ALTER TABLE paid_promotion_orders DISABLE TRIGGER protect_paid_promotion_orders');
        DB::statement('UPDATE paid_promotion_orders SET settlement_total=amount');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('ALTER TABLE paid_promotion_orders ENABLE TRIGGER protect_paid_promotion_orders');
        DB::statement('ALTER TABLE paid_promotion_orders ALTER COLUMN settlement_total SET NOT NULL');
        DB::statement('ALTER TABLE paid_promotion_orders DROP CONSTRAINT paid_promotion_orders_check');
        DB::statement(<<<'SQL'
            ALTER TABLE paid_promotion_orders ADD CONSTRAINT paid_promotion_orders_check CHECK (
              amount >= 0 AND deposit_applied >= 0 AND deposit_snapshot >= deposit_applied
              AND settlement_total > 0 AND settlement_total = amount + deposit_applied
              AND previous_tariff >= 0 AND settlement_total = tariff - previous_tariff
              AND status IN ('QUOTED','COMPLETED')
              AND ((status='COMPLETED')=(completed_at IS NOT NULL AND ledger_entry_id IS NOT NULL AND cycle_id IS NOT NULL)))
            SQL);
        DB::statement('ALTER TABLE paid_promotion_events DROP CONSTRAINT paid_promotion_events_check');
        DB::statement("ALTER TABLE paid_promotion_events ADD CONSTRAINT paid_promotion_events_check CHECK (kind IN ('ANNUAL','ACTIVATION') AND source_rank BETWEEN 0 AND 8 AND (amount>0 OR (kind='ANNUAL' AND amount=0)))");
        $definition = DB::selectOne("SELECT pg_get_functiondef('validate_paid_promotion_evidence()'::regprocedure) AS definition")->definition;
        $marker = "IF TG_TABLE_NAME='paid_promotion_orders' THEN IF NEW.status<>'COMPLETED' THEN RETURN NULL; END IF; END IF;";
        $replacement = <<<'SQL'
            IF TG_TABLE_NAME='paid_promotion_orders' THEN
              IF NEW.status<>'COMPLETED' THEN RETURN NULL; END IF;
              SELECT * INTO e FROM ledger_entries WHERE id=NEW.ledger_entry_id AND tenant_id=NEW.tenant_id;
              SELECT * INTO cycle FROM paid_promotion_cycles WHERE id=NEW.cycle_id AND tenant_id=NEW.tenant_id AND user_id=NEW.user_id;
              IF cycle.id IS NULL OR NEW.completed_at<cycle.starts_at OR NEW.completed_at>=cycle.ends_at
                OR e.id IS NULL OR e.sealed_at IS NULL OR e.asset_code<>'USDT'
                OR e.event_key<>'promotion_fee:'||NEW.id::text OR e.event_type<>'PROMOTION_ANNUAL_FEE'
                OR e.reference_type IS DISTINCT FROM 'PROMOTION_ORDER' OR e.reference_id IS DISTINCT FROM NEW.id
              THEN RAISE EXCEPTION 'Fee order requires owned sealed settlement evidence'; END IF;
              SELECT COUNT(*) INTO valid_count FROM ledger_postings p
                JOIN ledger_accounts a ON a.id=p.ledger_account_id AND a.tenant_id=p.tenant_id
                WHERE p.tenant_id=NEW.tenant_id AND p.ledger_entry_id=e.id AND a.asset_code='USDT'
                  AND ((a.account_type='TENANT_PROMOTION_FEE_REVENUE' AND a.user_id IS NULL AND p.delta=NEW.settlement_total)
                    OR (a.user_id=NEW.user_id AND EXISTS(SELECT 1 FROM wallets w WHERE w.id=a.wallet_id AND w.tenant_id=NEW.tenant_id AND w.user_id=NEW.user_id AND w.asset_code='USDT')
                      AND ((NEW.amount>0 AND a.account_type='USER_AVAILABLE' AND p.delta=-NEW.amount)
                        OR (NEW.deposit_applied>0 AND a.account_type='USER_SECURITY_DEPOSIT' AND p.delta=-NEW.deposit_applied))));
              IF valid_count <> 1+(NEW.amount>0)::integer+(NEW.deposit_applied>0)::integer
                OR (SELECT count(*) FROM ledger_postings WHERE ledger_entry_id=e.id)<>valid_count
              THEN RAISE EXCEPTION 'Fee order requires exact wallet and deposit contributions'; END IF;
              RETURN NULL;
            END IF;
            SQL;
        $definition = str_replace($marker, $replacement, $definition, $count);
        if ($count !== 1) {
            throw new RuntimeException('Unexpected fee evidence definition.');
        }
        $definition = str_replace('(SELECT COALESCE(SUM(amount),0) FROM paid_promotion_orders', '(SELECT COALESCE(SUM(settlement_total),0) FROM paid_promotion_orders', $definition);
        DB::unprepared($definition);
        Schema::create('account_activations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->uuid('user_id');
            $t->string('source_type', 12);
            $t->uuid('source_id');
            $t->uuid('ledger_entry_id');
            $t->timestampTz('activated_at');
            $t->timestampTz('created_at');
            $t->unique(['tenant_id', 'user_id']);
            $t->unique(['id', 'tenant_id']);
            $t->unique(['tenant_id', 'source_type', 'source_id']);
            $t->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            $t->foreign(['ledger_entry_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('ledger_entries');
        });
        Schema::create('account_activation_relations', function (Blueprint $t): void {
            $t->uuid('activation_id');
            $t->uuid('tenant_id');
            $t->uuid('ancestor_user_id');
            $t->unsignedInteger('depth');
            $t->primary(['activation_id', 'ancestor_user_id']);
            $t->unique(['activation_id', 'depth']);
            $t->foreign(['activation_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('account_activations');
            $t->foreign(['ancestor_user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            $t->index(['tenant_id', 'ancestor_user_id']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE account_activations ADD CHECK(source_type IN ('DEPOSIT','ANNUAL'));
            ALTER TABLE account_activation_relations ADD CHECK(depth>0);
            INSERT INTO account_activations
              SELECT gen_random_uuid(),tenant_id,user_id,source_type,source_id,ledger_entry_id,occurred_at,now()
              FROM (SELECT DISTINCT ON(tenant_id,user_id) * FROM (
                SELECT e.tenant_id,a.user_id,'DEPOSIT'::text AS source_type,e.id AS source_id,e.id AS ledger_entry_id,e.posted_at AS occurred_at
                  FROM ledger_entries e JOIN ledger_postings p ON p.ledger_entry_id=e.id AND p.tenant_id=e.tenant_id
                  JOIN ledger_accounts a ON a.id=p.ledger_account_id AND a.tenant_id=e.tenant_id
                  WHERE e.event_type='SECURITY_DEPOSIT_FUND' AND e.sealed_at IS NOT NULL AND e.asset_code='USDT'
                    AND a.account_type='USER_SECURITY_DEPOSIT' AND p.delta>0
                UNION ALL SELECT tenant_id,user_id,'ANNUAL',id,ledger_entry_id,completed_at FROM paid_promotion_orders WHERE status='COMPLETED'
              ) sources ORDER BY tenant_id,user_id,occurred_at,ledger_entry_id) firsts;
            WITH RECURSIVE ancestors AS (
              SELECT a.id AS activation_id,a.tenant_id,p.user_id,p.inviter_id,1 AS depth
                FROM account_activations a JOIN promotion_members m ON m.tenant_id=a.tenant_id AND m.user_id=a.user_id
                JOIN promotion_members p ON p.tenant_id=m.tenant_id AND p.id=m.inviter_id
              UNION ALL SELECT c.activation_id,c.tenant_id,p.user_id,p.inviter_id,c.depth+1
                FROM ancestors c JOIN promotion_members p ON p.id=c.inviter_id AND p.tenant_id=c.tenant_id
            ) INSERT INTO account_activation_relations SELECT activation_id,tenant_id,user_id,depth FROM ancestors;
            CREATE TRIGGER account_activations_immutable BEFORE UPDATE OR DELETE ON account_activations FOR EACH ROW EXECUTE FUNCTION reject_ledger_history_mutation();
            CREATE TRIGGER account_activation_relations_immutable BEFORE UPDATE OR DELETE ON account_activation_relations FOR EACH ROW EXECUTE FUNCTION reject_ledger_history_mutation();
            CREATE OR REPLACE FUNCTION validate_account_activation() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE act account_activations%ROWTYPE; source_ok boolean;
            BEGIN
              IF TG_TABLE_NAME='account_activation_relations' THEN
                SELECT * INTO act FROM account_activations WHERE id=NEW.activation_id AND tenant_id=NEW.tenant_id;
              ELSE act:=NEW; END IF;
              SELECT EXISTS(SELECT 1 FROM ledger_entries e JOIN ledger_postings p ON p.ledger_entry_id=e.id AND p.tenant_id=e.tenant_id
                JOIN ledger_accounts a ON a.id=p.ledger_account_id AND a.tenant_id=e.tenant_id
                WHERE e.id=act.ledger_entry_id AND e.tenant_id=act.tenant_id AND e.sealed_at IS NOT NULL AND e.asset_code='USDT'
                  AND ((act.source_type='DEPOSIT' AND act.source_id=e.id AND e.event_type='SECURITY_DEPOSIT_FUND' AND e.posted_at=act.activated_at
                    AND a.user_id=act.user_id AND a.account_type='USER_SECURITY_DEPOSIT' AND p.delta>0)
                  OR (act.source_type='ANNUAL' AND e.event_type='PROMOTION_ANNUAL_FEE' AND EXISTS(SELECT 1 FROM paid_promotion_orders o
                    WHERE o.id=act.source_id AND o.tenant_id=act.tenant_id AND o.user_id=act.user_id AND o.status='COMPLETED'
                      AND o.ledger_entry_id=e.id AND o.completed_at=act.activated_at)))) INTO source_ok;
              IF NOT source_ok THEN RAISE EXCEPTION 'Activation requires an owned completed funding or annual fee'; END IF;
              IF EXISTS(WITH RECURSIVE chain AS (
                SELECT p.user_id,p.inviter_id,1 AS depth FROM promotion_members m JOIN promotion_members p ON p.id=m.inviter_id AND p.tenant_id=m.tenant_id WHERE m.tenant_id=act.tenant_id AND m.user_id=act.user_id
                UNION ALL SELECT p.user_id,p.inviter_id,c.depth+1 FROM chain c JOIN promotion_members p ON p.id=c.inviter_id AND p.tenant_id=act.tenant_id
              ) SELECT 1 FROM chain FULL JOIN (SELECT ancestor_user_id,depth FROM account_activation_relations WHERE activation_id=act.id AND tenant_id=act.tenant_id) r
                ON r.ancestor_user_id=chain.user_id AND r.depth=chain.depth WHERE r.ancestor_user_id IS NULL OR chain.user_id IS NULL)
              THEN RAISE EXCEPTION 'Activation relations must match complete scoped ancestry'; END IF;
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER account_activation_evidence AFTER INSERT ON account_activations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_account_activation();
            CREATE CONSTRAINT TRIGGER account_activation_relation_evidence AFTER INSERT ON account_activation_relations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_account_activation();
            SQL);
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function down(): void
    {
        throw new RuntimeException('Activation and fee settlement evidence is immutable; use a forward migration.');
    }
};
