<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ledger_accounts_type_check', 'ledger_accounts_negative_policy_check'] as $name) {
            $old = DB::selectOne('SELECT pg_get_expr(conbin, conrelid) AS definition FROM pg_constraint WHERE conrelid = ?::regclass AND conname = ?', ['ledger_accounts', $name])->definition;
            DB::statement("ALTER TABLE ledger_accounts DROP CONSTRAINT {$name}");
            DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT {$name} CHECK (($old) OR account_type = 'TENANT_ADJUSTMENT_CLEARING')");
        }
        Schema::create('wallet_adjustments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('user_id');
            $t->uuid('wallet_id');
            $t->foreignUuid('actor_id')->constrained('admin_users');
            $t->string('actor_name');
            $t->uuid('request_id');
            $t->string('asset_code', 12);
            $t->string('direction', 8);
            $t->decimal('amount', 38, 18);
            $t->decimal('balance_before', 38, 18);
            $t->decimal('balance_after', 38, 18);
            $t->string('reason', 500);
            $t->uuid('ledger_entry_id')->unique();
            $t->timestampTz('created_at', 6);
            $t->unique(['tenant_id', 'user_id', 'request_id']);
            $t->index(['tenant_id', 'user_id', 'created_at']);
            $t->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            $t->foreign(['wallet_id', 'tenant_id', 'user_id', 'asset_code'], 'adjustment_wallet_scope_fk')->references(['id', 'tenant_id', 'user_id', 'asset_code'])->on('wallets');
            $t->foreign(['ledger_entry_id', 'tenant_id', 'asset_code'], 'adjustment_entry_scope_fk')->references(['id', 'tenant_id', 'asset_code'])->on('ledger_entries');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE wallet_adjustments ADD CHECK (amount > 0 AND balance_before >= 0 AND balance_after >= 0
                AND length(trim(reason)) > 0 AND asset_code IN ('USDT','USDC','ETH','BTC')
                AND ((direction='INCREASE' AND balance_after=balance_before+amount) OR (direction='DECREASE' AND balance_after=balance_before-amount)));
            CREATE OR REPLACE FUNCTION protect_wallet_adjustment() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Wallet adjustments are immutable'; END $$;
            CREATE TRIGGER wallet_adjustment_immutable BEFORE UPDATE OR DELETE ON wallet_adjustments FOR EACH ROW EXECUTE FUNCTION protect_wallet_adjustment();
            CREATE OR REPLACE FUNCTION verify_wallet_adjustment() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE r wallet_adjustments%ROWTYPE; e ledger_entries%ROWTYPE; signed_amount numeric; n integer;
            BEGIN
                IF TG_TABLE_NAME='wallet_adjustments' THEN
                    r:=NEW;
                    SELECT * INTO e FROM ledger_entries WHERE id=r.ledger_entry_id AND tenant_id=r.tenant_id;
                ELSE
                    IF NEW.sealed_at IS NULL THEN RETURN NEW; END IF;
                    IF NEW.event_type <> 'WALLET_ADJUSTMENT' THEN
                        IF EXISTS(SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id WHERE p.ledger_entry_id=NEW.id AND a.account_type='TENANT_ADJUSTMENT_CLEARING') THEN RAISE EXCEPTION 'Adjustment account requires adjustment evidence'; END IF;
                        RETURN NEW;
                    END IF;
                    e:=NEW;
                    SELECT * INTO r FROM wallet_adjustments WHERE ledger_entry_id=e.id AND tenant_id=e.tenant_id;
                END IF;
                IF r.id IS NULL OR e.id IS NULL OR e.sealed_at IS NULL OR e.event_type <> 'WALLET_ADJUSTMENT' OR e.reference_type <> 'WALLET_ADJUSTMENT' OR e.reference_id IS DISTINCT FROM r.id OR e.asset_code <> r.asset_code THEN RAISE EXCEPTION 'Missing adjustment evidence'; END IF;
                IF NOT EXISTS(SELECT 1 FROM wallets WHERE id=r.wallet_id AND tenant_id=r.tenant_id AND user_id=r.user_id AND asset_code=r.asset_code) THEN RAISE EXCEPTION 'Adjustment wallet scope mismatch'; END IF;
                signed_amount:=CASE WHEN r.direction='INCREASE' THEN r.amount ELSE -r.amount END;
                SELECT count(*) INTO n FROM ledger_postings WHERE ledger_entry_id=e.id;
                IF n<>2 OR NOT EXISTS(SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id WHERE p.ledger_entry_id=e.id AND a.tenant_id=r.tenant_id AND a.user_id=r.user_id AND a.wallet_id=r.wallet_id AND a.account_type='USER_AVAILABLE' AND p.delta=signed_amount)
                OR NOT EXISTS(SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id WHERE p.ledger_entry_id=e.id AND a.tenant_id=r.tenant_id AND a.user_id IS NULL AND a.wallet_id IS NULL AND a.account_type='TENANT_ADJUSTMENT_CLEARING' AND p.delta=-signed_amount) THEN RAISE EXCEPTION 'Adjustment postings mismatch'; END IF;
                RETURN NEW;
            END $$;
            CREATE CONSTRAINT TRIGGER wallet_adjustment_evidence AFTER INSERT ON wallet_adjustments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_wallet_adjustment();
            CREATE CONSTRAINT TRIGGER wallet_adjustment_entry_evidence AFTER INSERT OR UPDATE ON ledger_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_wallet_adjustment();
            SQL);
        $id = DB::table('permissions')->where('name', 'wallet.adjust')->value('id');
        if (! $id) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert(['id' => $id, 'name' => 'wallet.adjust', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $role) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Wallet adjustment history must be retained.');
    }
};
