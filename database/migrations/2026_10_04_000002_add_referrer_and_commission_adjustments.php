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
        Schema::create('manual_commission_adjustments', function (Blueprint $t): void {
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
            $t->decimal('commission_before', 38, 18);
            $t->decimal('commission_after', 38, 18);
            $t->string('reason', 500);
            $t->uuid('ledger_entry_id')->unique();
            $t->timestampTz('created_at', 6);
            $t->unique(['tenant_id', 'user_id', 'request_id']);
            $t->index(['tenant_id', 'user_id', 'created_at']);
            $t->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            $t->foreign(['wallet_id', 'tenant_id', 'user_id', 'asset_code'], 'manual_commission_wallet_fk')->references(['id', 'tenant_id', 'user_id', 'asset_code'])->on('wallets');
            $t->foreign(['ledger_entry_id', 'tenant_id', 'asset_code'], 'manual_commission_entry_fk')->references(['id', 'tenant_id', 'asset_code'])->on('ledger_entries');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE manual_commission_adjustments ADD CHECK (amount > 0 AND balance_before >= 0 AND balance_after >= 0
                AND length(trim(reason)) > 0 AND asset_code = 'USDT' AND amount=round(amount,8) AND commission_before>=0 AND commission_after>=0
                AND commission_after-commission_before=balance_after-balance_before
                AND ((direction='INCREASE' AND balance_after=balance_before+amount) OR (direction='DECREASE' AND balance_after=balance_before-amount)));
            CREATE OR REPLACE FUNCTION protect_manual_commission_adjustment() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Wallet adjustments are immutable'; END $$;
            CREATE TRIGGER manual_commission_adjustment_immutable BEFORE UPDATE OR DELETE ON manual_commission_adjustments FOR EACH ROW EXECUTE FUNCTION protect_manual_commission_adjustment();
            CREATE OR REPLACE FUNCTION verify_manual_commission_adjustment() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE r manual_commission_adjustments%ROWTYPE; e ledger_entries%ROWTYPE; signed_amount numeric; n integer;
            BEGIN
                IF TG_TABLE_NAME='manual_commission_adjustments' THEN
                    r:=NEW;
                    SELECT * INTO e FROM ledger_entries WHERE id=r.ledger_entry_id AND tenant_id=r.tenant_id;
                ELSE
                    IF NEW.sealed_at IS NULL THEN RETURN NEW; END IF;
                    IF NEW.event_type <> 'MANUAL_COMMISSION' THEN
                        RETURN NEW;
                    END IF;
                    e:=NEW;
                    SELECT * INTO r FROM manual_commission_adjustments WHERE ledger_entry_id=e.id AND tenant_id=e.tenant_id;
                END IF;
                IF r.id IS NULL OR e.id IS NULL OR e.sealed_at IS NULL OR e.event_type <> 'MANUAL_COMMISSION' OR e.reference_type <> 'MANUAL_COMMISSION' OR e.reference_id IS DISTINCT FROM r.id OR e.asset_code <> r.asset_code THEN RAISE EXCEPTION 'Missing adjustment evidence'; END IF;
                IF NOT EXISTS(SELECT 1 FROM wallets WHERE id=r.wallet_id AND tenant_id=r.tenant_id AND user_id=r.user_id AND asset_code=r.asset_code) THEN RAISE EXCEPTION 'Adjustment wallet scope mismatch'; END IF;
                signed_amount:=CASE WHEN r.direction='INCREASE' THEN r.amount ELSE -r.amount END;
                SELECT count(*) INTO n FROM ledger_postings WHERE ledger_entry_id=e.id;
                IF n<>2 OR NOT EXISTS(SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id WHERE p.ledger_entry_id=e.id AND a.tenant_id=r.tenant_id AND a.user_id=r.user_id AND a.wallet_id=r.wallet_id AND a.account_type='USER_AVAILABLE' AND p.delta=signed_amount)
                OR NOT EXISTS(SELECT 1 FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id WHERE p.ledger_entry_id=e.id AND a.tenant_id=r.tenant_id AND a.user_id IS NULL AND a.wallet_id IS NULL AND a.account_type='TENANT_COMMISSION_CLEARING' AND p.delta=-signed_amount) THEN RAISE EXCEPTION 'Adjustment postings mismatch'; END IF;
                RETURN NEW;
            END $$;
            CREATE CONSTRAINT TRIGGER manual_commission_adjustment_evidence AFTER INSERT ON manual_commission_adjustments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_manual_commission_adjustment();
            CREATE CONSTRAINT TRIGGER manual_commission_adjustment_entry_evidence AFTER INSERT OR UPDATE ON ledger_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_manual_commission_adjustment();
            SQL);
        Schema::table('promotion_members', function (Blueprint $t) {
            $t->unsignedBigInteger('referrer_revision')->default(0);
        });
        Schema::create('referrer_changes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('user_id');
            $t->uuid('member_id');
            $t->uuid('old_inviter_id')->nullable();
            $t->uuid('new_inviter_id');
            $t->unsignedBigInteger('revision');
            $t->foreignUuid('actor_id')->constrained('admin_users');
            $t->string('actor_name');
            $t->uuid('request_id');
            $t->string('reason', 500);
            $t->unsignedBigInteger('descendants');
            $t->bigInteger('transaction_id')->default(DB::raw('txid_current()'));
            $t->timestampTz('created_at', 6);
            $t->unique(['tenant_id', 'user_id', 'request_id']);
            $t->unique(['member_id', 'revision']);
            $t->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            foreach (['member_id', 'old_inviter_id', 'new_inviter_id'] as $column) {
                $t->foreign([$column, 'tenant_id'])->references(['id', 'tenant_id'])->on('promotion_members');
            }
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE referrer_changes ADD CHECK (revision>0 AND length(trim(reason))>0 AND new_inviter_id IS DISTINCT FROM old_inviter_id AND new_inviter_id<>member_id);
            CREATE TRIGGER referrer_changes_immutable BEFORE UPDATE OR DELETE ON referrer_changes FOR EACH ROW EXECUTE FUNCTION protect_manual_commission_adjustment();
            CREATE OR REPLACE FUNCTION verify_referrer_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS(SELECT 1 FROM promotion_members m WHERE m.id=NEW.member_id AND m.tenant_id=NEW.tenant_id AND m.user_id=NEW.user_id AND m.referrer_revision>=NEW.revision) THEN
                    RAISE EXCEPTION 'Referrer change was not applied';
                END IF;
                RETURN NEW;
            END $$;
            CREATE CONSTRAINT TRIGGER referrer_change_evidence AFTER INSERT ON referrer_changes DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_referrer_change();
            SQL);
        // Preserve all other foundation guards, including later rank migrations.
        $definition = DB::selectOne("SELECT pg_get_functiondef('protect_promotion_foundation()'::regprocedure) AS definition")->definition;
        $definition = str_replace('OR NEW.inviter_id IS DISTINCT FROM OLD.inviter_id ', '', $definition);
        $guard = <<<'SQL'
                    IF TG_TABLE_NAME = 'promotion_members' AND TG_OP='UPDATE' THEN
                        IF NEW.inviter_id IS DISTINCT FROM OLD.inviter_id THEN
                            PERFORM 1 FROM tenants WHERE id=NEW.tenant_id FOR UPDATE;
                            IF NEW.inviter_id IS NULL OR NEW.referrer_revision<>OLD.referrer_revision+1 OR NOT EXISTS (
                                SELECT 1 FROM referrer_changes r WHERE r.member_id=NEW.id AND r.tenant_id=NEW.tenant_id AND r.user_id=NEW.user_id
                                AND r.revision=NEW.referrer_revision AND r.old_inviter_id IS NOT DISTINCT FROM OLD.inviter_id
                                AND r.new_inviter_id=NEW.inviter_id AND r.transaction_id=txid_current()
                            ) THEN RAISE EXCEPTION 'Referrer change requires matching evidence'; END IF;
                        ELSIF NEW.referrer_revision<>OLD.referrer_revision THEN
                            RAISE EXCEPTION 'Referrer revision requires a relationship change';
                        END IF;
                    END IF;
                    IF TG_TABLE_NAME = 'promotion_members' AND TG_OP IN ('INSERT','UPDATE') THEN
            SQL;
        $definition = str_replace("IF TG_TABLE_NAME = 'promotion_members' AND TG_OP = 'INSERT' THEN", $guard, $definition);
        DB::unprepared($definition);
        foreach (['users.referrer.manage', 'commissions.adjust'] as $name) {
            $id = DB::table('permissions')->where('name', $name)->value('id');
            if (! $id) {
                $id = (string) Str::uuid();
                DB::table('permissions')->insert(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $role) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Recommendation and commission adjustment history must be retained.');
    }
};
