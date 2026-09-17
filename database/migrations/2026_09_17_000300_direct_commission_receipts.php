<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $definition = DB::selectOne("SELECT pg_get_functiondef('validate_commission_evidence()'::regprocedure) AS definition")->definition;
        $definition = str_replace("(a.account_type='USER_COMMISSION' AND a.user_id=NEW.user_id AND p.delta=NEW.amount)", "(a.account_type='USER_AVAILABLE' AND a.user_id=NEW.user_id AND p.delta=NEW.amount AND EXISTS (SELECT 1 FROM wallets w WHERE w.id=a.wallet_id AND w.tenant_id=NEW.tenant_id AND w.user_id=NEW.user_id AND w.asset_code='USDT'))", $definition, $count);
        if ($count !== 1) { throw new RuntimeException('Unexpected commission evidence definition.'); }
        DB::unprepared($definition);
        $definition = DB::selectOne("SELECT pg_get_functiondef('validate_paid_promotion_evidence()'::regprocedure) AS definition")->definition;
        $definition = str_replace("a.account_type=CASE WHEN TG_TABLE_NAME='paid_promotion_shares' THEN 'USER_COMMISSION' ELSE 'USER_AVAILABLE' END AND a.user_id=owner_id", "a.account_type='USER_AVAILABLE' AND a.user_id=owner_id AND EXISTS (SELECT 1 FROM wallets w WHERE w.id=a.wallet_id AND w.tenant_id=NEW.tenant_id AND w.user_id=owner_id AND w.asset_code='USDT')", $definition, $count);
        if ($count !== 1) { throw new RuntimeException('Unexpected promotion evidence definition.'); }
        DB::unprepared($definition);
        Schema::create('commission_balance_consolidations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $t->uuid('user_id');
            $t->uuid('source_account_id')->unique();
            $t->uuid('destination_account_id');
            $t->decimal('amount', 20, 8);
            $t->string('asset_code', 12)->default('USDT');
            $t->uuid('ledger_entry_id')->unique();
            $t->timestampTz('processed_at');
            $t->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            foreach (['source_account_id', 'destination_account_id'] as $column) {
                $t->foreign([$column, 'tenant_id', 'asset_code'])->references(['id', 'tenant_id', 'asset_code'])->on('ledger_accounts');
            }
            $t->foreign(['ledger_entry_id', 'tenant_id', 'asset_code'])->references(['id', 'tenant_id', 'asset_code'])->on('ledger_entries');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE commission_balance_consolidations ADD CONSTRAINT commission_consolidation_amount CHECK(amount>0 AND asset_code='USDT' AND source_account_id<>destination_account_id);
            CREATE TRIGGER commission_consolidations_immutable BEFORE UPDATE OR DELETE ON commission_balance_consolidations FOR EACH ROW EXECUTE FUNCTION reject_ledger_history_mutation();
            CREATE OR REPLACE FUNCTION validate_commission_consolidation() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE entry ledger_entries%ROWTYPE; matches integer;
            BEGIN
              SELECT * INTO entry FROM ledger_entries WHERE id=NEW.ledger_entry_id AND tenant_id=NEW.tenant_id;
              SELECT count(*) INTO matches FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id AND a.tenant_id=p.tenant_id
                WHERE p.ledger_entry_id=entry.id AND p.tenant_id=NEW.tenant_id AND a.user_id=NEW.user_id AND a.asset_code='USDT'
                AND ((a.id=NEW.source_account_id AND a.account_type='USER_COMMISSION' AND p.delta=-NEW.amount AND a.balance=0)
                  OR (a.id=NEW.destination_account_id AND a.account_type='USER_AVAILABLE' AND p.delta=NEW.amount));
              IF entry.id IS NULL OR entry.sealed_at IS NULL OR entry.asset_code<>'USDT'
                OR entry.event_type<>'COMMISSION_BALANCE_CONSOLIDATED' OR entry.event_key<>'commission_consolidation:'||NEW.source_account_id::text
                OR entry.reference_type IS DISTINCT FROM 'COMMISSION_CONSOLIDATION' OR entry.reference_id IS DISTINCT FROM NEW.id
                OR matches<>2 OR (SELECT count(*) FROM ledger_postings WHERE ledger_entry_id=entry.id)<>2
              THEN RAISE EXCEPTION 'Consolidation requires exact sealed full-balance evidence'; END IF;
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER commission_consolidation_evidence AFTER INSERT ON commission_balance_consolidations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_commission_consolidation();
            CREATE OR REPLACE FUNCTION prohibit_commission_balance_credit() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.delta>0 AND EXISTS(SELECT 1 FROM ledger_accounts WHERE id=NEW.ledger_account_id AND tenant_id=NEW.tenant_id AND account_type='USER_COMMISSION') THEN
                RAISE EXCEPTION 'Commission income must credit the USDT wallet';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER commission_balance_credit_retired BEFORE INSERT ON ledger_postings FOR EACH ROW EXECUTE FUNCTION prohibit_commission_balance_credit();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Commission receipts contain immutable financial evidence; use a forward migration.');
    }
};
