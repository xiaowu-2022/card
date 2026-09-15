<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE ledger_accounts DROP CONSTRAINT ledger_accounts_type_check;
            ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_check CHECK (account_type IN ('USER_AVAILABLE','USER_SECURITY_DEPOSIT','USER_WITHDRAWAL_HOLD','USER_CARD_ISSUE_HOLD','USER_CARD_FUNDING_HOLD','USER_COMMISSION','TENANT_TOPUP_CLEARING','TENANT_WITHDRAWAL_CLEARING','TENANT_CARD_FUNDING_CLEARING','TENANT_FEE_REVENUE','TENANT_COMMISSION_CLEARING'));
            ALTER TABLE ledger_accounts DROP CONSTRAINT ledger_accounts_owner_check;
            ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_owner_check CHECK (
                (account_type = 'USER_COMMISSION' AND wallet_id IS NULL AND user_id IS NOT NULL AND asset_code = 'USDT') OR
                (account_type LIKE 'USER_%' AND account_type <> 'USER_COMMISSION' AND wallet_id IS NOT NULL AND user_id IS NOT NULL) OR
                (account_type LIKE 'TENANT_%' AND wallet_id IS NULL AND user_id IS NULL));
            ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_account_user_owner_fk FOREIGN KEY (user_id, tenant_id) REFERENCES users (id, tenant_id);
            ALTER TABLE ledger_accounts DROP CONSTRAINT ledger_accounts_negative_policy_check;
            ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_negative_policy_check CHECK (account_type IN ('TENANT_TOPUP_CLEARING','TENANT_WITHDRAWAL_CLEARING','TENANT_CARD_FUNDING_CLEARING','TENANT_COMMISSION_CLEARING') OR balance >= 0);
            DROP INDEX ledger_accounts_tenant_system_unique;
            CREATE UNIQUE INDEX ledger_accounts_tenant_system_unique ON ledger_accounts (tenant_id, asset_code, account_type) WHERE wallet_id IS NULL AND user_id IS NULL;
            CREATE UNIQUE INDEX ledger_accounts_user_commission_unique ON ledger_accounts (tenant_id, user_id, asset_code, account_type) WHERE account_type = 'USER_COMMISSION';
            SQL);
        Schema::create('promotion_company_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants');
            $table->string('invitation_code', 24);
            $table->timestampsTz();
            $table->unique(['id', 'tenant_id']);
        });
        Schema::table('registration_challenges', function (Blueprint $table): void {
            $table->uuid('promotion_inviter_id')->nullable();
            $table->uuid('promotion_company_invitation_id')->nullable();
            $table->foreign(['promotion_inviter_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('promotion_members')->restrictOnDelete();
            $table->foreign(['promotion_company_invitation_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('promotion_company_invitations')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE promotion_company_invitations ADD CONSTRAINT company_invitation_code_format CHECK (invitation_code ~ '^[A-F0-9]{24}$');
            CREATE TRIGGER company_invitation_immutable BEFORE UPDATE OR DELETE ON promotion_company_invitations FOR EACH ROW EXECUTE FUNCTION reject_ledger_history_mutation();
            ALTER TABLE registration_challenges ADD CONSTRAINT registration_invitation_kind CHECK (NOT (promotion_inviter_id IS NOT NULL AND promotion_company_invitation_id IS NOT NULL));
            CREATE OR REPLACE FUNCTION protect_registration_invitation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.promotion_inviter_id IS DISTINCT FROM OLD.promotion_inviter_id OR NEW.promotion_company_invitation_id IS DISTINCT FROM OLD.promotion_company_invitation_id THEN
                    RAISE EXCEPTION 'Registration invitation is immutable';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER registration_invitation_immutable BEFORE UPDATE ON registration_challenges FOR EACH ROW EXECUTE FUNCTION protect_registration_invitation();
            SQL);
        Schema::create('promotion_funding_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('user_id');
            $table->uuid('funding_entry_id')->unique();
            $table->string('asset_code', 12)->default('USDT');
            $table->decimal('amount', 20, 8);
            $table->timestampTz('funded_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['id', 'tenant_id']);
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            $table->foreign(['funding_entry_id', 'tenant_id', 'asset_code'])->references(['id', 'tenant_id', 'asset_code'])->on('ledger_entries');
            $table->index(['tenant_id', 'user_id', 'funded_at']);
        });
        Schema::create('commission_awards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('funding_event_id');
            $table->uuid('user_id');
            $table->uuid('level_id');
            $table->unsignedInteger('level_revision');
            $table->decimal('level_reward', 20, 8);
            $table->decimal('amount', 20, 8);
            $table->string('asset_code', 12)->default('USDT');
            $table->uuid('ledger_entry_id')->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['funding_event_id', 'user_id']);
            $table->foreign(['funding_event_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('promotion_funding_events');
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            $table->foreign(['level_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('promotion_levels');
            $table->foreign(['ledger_entry_id', 'tenant_id', 'asset_code'])->references(['id', 'tenant_id', 'asset_code'])->on('ledger_entries');
            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
        Schema::create('commission_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('user_id');
            $table->uuid('request_id');
            $table->decimal('amount', 20, 8);
            $table->string('asset_code', 12)->default('USDT');
            $table->uuid('ledger_entry_id')->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'user_id', 'request_id']);
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            $table->foreign(['ledger_entry_id', 'tenant_id', 'asset_code'])->references(['id', 'tenant_id', 'asset_code'])->on('ledger_entries');
        });
        foreach (['promotion_funding_events', 'commission_awards', 'commission_transfers'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_amount_valid CHECK (asset_code = 'USDT' AND amount > 0)");
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_ledger_history_mutation()");
        }
        DB::statement('ALTER TABLE commission_awards ADD CONSTRAINT commission_awards_integer CHECK (amount = trunc(amount) AND level_reward = trunc(level_reward) AND level_reward >= amount AND level_revision > 0)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_commission_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE e ledger_entries%ROWTYPE; valid_count integer; total_count integer; expected_type text; expected_key text;
            BEGIN
                IF TG_TABLE_NAME = 'promotion_funding_events' THEN
                    SELECT * INTO e FROM ledger_entries WHERE tenant_id = NEW.tenant_id AND id = NEW.funding_entry_id;
                    SELECT count(*) INTO valid_count FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id AND a.tenant_id=p.tenant_id
                    WHERE p.tenant_id=NEW.tenant_id AND p.ledger_entry_id=e.id AND a.user_id=NEW.user_id
                    AND ((a.account_type='USER_AVAILABLE' AND p.delta=-NEW.amount) OR (a.account_type='USER_SECURITY_DEPOSIT' AND p.delta=NEW.amount));
                    expected_type := 'SECURITY_DEPOSIT_FUND';
                ELSE
                    SELECT * INTO e FROM ledger_entries WHERE tenant_id = NEW.tenant_id AND id = NEW.ledger_entry_id;
                    expected_type := CASE WHEN TG_TABLE_NAME='commission_awards' THEN 'COMMISSION_EARN' ELSE 'COMMISSION_TRANSFER' END;
                    expected_key := CASE WHEN TG_TABLE_NAME='commission_awards' THEN 'commission_award:' ELSE 'commission_transfer:' END || NEW.id::text;
                    IF e.event_key IS DISTINCT FROM expected_key OR e.reference_id IS DISTINCT FROM NEW.id THEN RAISE EXCEPTION 'Commission event identity mismatch'; END IF;
                    SELECT count(*) INTO valid_count FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id AND a.tenant_id=p.tenant_id
                    WHERE p.tenant_id=NEW.tenant_id AND p.ledger_entry_id=e.id AND (
                        (TG_TABLE_NAME='commission_awards' AND ((a.account_type='TENANT_COMMISSION_CLEARING' AND a.user_id IS NULL AND p.delta=-NEW.amount) OR (a.account_type='USER_COMMISSION' AND a.user_id=NEW.user_id AND p.delta=NEW.amount))) OR
                        (TG_TABLE_NAME='commission_transfers' AND a.user_id=NEW.user_id AND ((a.account_type='USER_COMMISSION' AND p.delta=-NEW.amount) OR (a.account_type='USER_AVAILABLE' AND p.delta=NEW.amount))));
                END IF;
                SELECT count(*) INTO total_count FROM ledger_postings WHERE tenant_id=NEW.tenant_id AND ledger_entry_id=e.id;
                IF e.id IS NULL OR e.sealed_at IS NULL OR e.asset_code <> 'USDT' OR e.event_type <> expected_type OR valid_count <> 2 OR total_count <> 2 THEN
                    RAISE EXCEPTION 'Commission requires exact sealed financial evidence';
                END IF;
                RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER promotion_funding_evidence AFTER INSERT ON promotion_funding_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_commission_evidence();
            CREATE CONSTRAINT TRIGGER commission_award_evidence AFTER INSERT ON commission_awards DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_commission_evidence();
            CREATE CONSTRAINT TRIGGER commission_transfer_evidence AFTER INSERT ON commission_transfers DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_commission_evidence();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Commission accounting contains immutable financial history; use a forward migration.');
    }
};
