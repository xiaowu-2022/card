<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('user_id');
            $table->string('asset_code', 12);
            $table->string('status', 24);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id', 'asset_code']);
            $table->unique(['id', 'tenant_id', 'user_id', 'asset_code']);
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('wallet_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('account_type', 40);
            $table->string('asset_code', 12);
            $table->decimal('balance', 20, 8)->default('0.00000000');
            $table->string('status', 24);
            $table->timestampsTz();

            $table->unique(['wallet_id', 'account_type']);
            $table->unique(['id', 'tenant_id', 'asset_code']);
            $table->foreign(['wallet_id', 'tenant_id', 'user_id', 'asset_code'], 'ledger_account_wallet_owner_fk')
                ->references(['id', 'tenant_id', 'user_id', 'asset_code'])->on('wallets')->restrictOnDelete();
            $table->index(['tenant_id', 'user_id', 'asset_code']);
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('asset_code', 12);
            $table->string('event_key', 255);
            $table->char('event_hash', 64);
            $table->string('event_type', 64);
            $table->string('reference_type', 64)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->uuid('reversal_of_entry_id')->nullable();
            $table->timestampTz('posted_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'event_key']);
            $table->unique(['id', 'tenant_id', 'asset_code']);
            $table->foreign(['reversal_of_entry_id', 'tenant_id', 'asset_code'], 'ledger_entry_reversal_fk')
                ->references(['id', 'tenant_id', 'asset_code'])->on('ledger_entries')->restrictOnDelete();
            $table->index(['tenant_id', 'reference_type', 'reference_id']);
            $table->index(['tenant_id', 'posted_at']);
        });

        Schema::create('ledger_postings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('asset_code', 12);
            $table->uuid('ledger_entry_id');
            $table->uuid('ledger_account_id');
            $table->decimal('delta', 20, 8);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['ledger_entry_id', 'ledger_account_id']);
            $table->foreign(['ledger_entry_id', 'tenant_id', 'asset_code'], 'ledger_posting_entry_fk')
                ->references(['id', 'tenant_id', 'asset_code'])->on('ledger_entries')->restrictOnDelete();
            $table->foreign(['ledger_account_id', 'tenant_id', 'asset_code'], 'ledger_posting_account_fk')
                ->references(['id', 'tenant_id', 'asset_code'])->on('ledger_accounts')->restrictOnDelete();
            $table->index(['tenant_id', 'ledger_account_id', 'created_at']);
        });

        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_asset_check CHECK (asset_code ~ '^[A-Z0-9]{3,12}$')");
        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_status_check CHECK (status IN ('ACTIVE','SUSPENDED','CLOSED'))");
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_asset_check CHECK (asset_code ~ '^[A-Z0-9]{3,12}$')");
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_status_check CHECK (status IN ('ACTIVE','CLOSED'))");
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_check CHECK (account_type IN ('USER_AVAILABLE','USER_SECURITY_DEPOSIT','USER_WITHDRAWAL_HOLD','USER_CARD_ISSUE_HOLD','USER_CARD_FUNDING_HOLD','TENANT_TOPUP_CLEARING','TENANT_WITHDRAWAL_CLEARING','TENANT_CARD_FUNDING_CLEARING','TENANT_FEE_REVENUE'))");
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_owner_check CHECK ((account_type LIKE 'USER_%' AND wallet_id IS NOT NULL AND user_id IS NOT NULL) OR (account_type LIKE 'TENANT_%' AND wallet_id IS NULL AND user_id IS NULL))");
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_negative_policy_check CHECK ((account_type IN ('TENANT_TOPUP_CLEARING','TENANT_WITHDRAWAL_CLEARING','TENANT_CARD_FUNDING_CLEARING')) OR balance >= 0)");
        DB::statement('CREATE UNIQUE INDEX ledger_accounts_tenant_system_unique ON ledger_accounts (tenant_id, asset_code, account_type) WHERE wallet_id IS NULL');

        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_asset_check CHECK (asset_code ~ '^[A-Z0-9]{3,12}$')");
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_event_key_check CHECK (event_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{2,254}$')");
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_event_hash_check CHECK (event_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_event_type_check CHECK (event_type ~ '^[A-Z][A-Z0-9_]{2,63}$')");
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_reference_check CHECK ((reference_type IS NULL AND reference_id IS NULL) OR (reference_type ~ '^[A-Z][A-Z0-9_]{2,63}$' AND reference_id IS NOT NULL))");
        DB::statement("ALTER TABLE ledger_postings ADD CONSTRAINT ledger_postings_asset_check CHECK (asset_code ~ '^[A-Z0-9]{3,12}$')");
        DB::statement('ALTER TABLE ledger_postings ADD CONSTRAINT ledger_postings_nonzero_check CHECK (delta <> 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_ledger_history_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'ledger history is immutable' USING ERRCODE = '55000';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_entries_immutable
            BEFORE UPDATE OR DELETE ON ledger_entries
            FOR EACH ROW EXECUTE FUNCTION reject_ledger_history_mutation();

            CREATE TRIGGER ledger_postings_immutable
            BEFORE UPDATE OR DELETE ON ledger_postings
            FOR EACH ROW EXECUTE FUNCTION reject_ledger_history_mutation();

            CREATE OR REPLACE FUNCTION validate_balanced_ledger_entry() RETURNS trigger AS $$
            DECLARE
                target_entry uuid;
                posting_count bigint;
                posting_total numeric(20,8);
            BEGIN
                IF TG_TABLE_NAME = 'ledger_entries' THEN
                    target_entry := NEW.id;
                ELSE
                    target_entry := NEW.ledger_entry_id;
                END IF;
                SELECT COUNT(*), COALESCE(SUM(delta), 0) INTO posting_count, posting_total
                FROM ledger_postings WHERE ledger_entry_id = target_entry;
                IF posting_count < 2 OR posting_total <> 0 THEN
                    RAISE EXCEPTION 'ledger entry must contain at least two postings that sum exactly to zero' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER ledger_entry_balanced_from_entry
            AFTER INSERT ON ledger_entries DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION validate_balanced_ledger_entry();

            CREATE CONSTRAINT TRIGGER ledger_entry_balanced_from_posting
            AFTER INSERT ON ledger_postings DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION validate_balanced_ledger_entry();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_postings');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_accounts');
        Schema::dropIfExists('wallets');
        DB::statement('DROP FUNCTION IF EXISTS validate_balanced_ledger_entry()');
        DB::statement('DROP FUNCTION IF EXISTS reject_ledger_history_mutation()');
    }
};
