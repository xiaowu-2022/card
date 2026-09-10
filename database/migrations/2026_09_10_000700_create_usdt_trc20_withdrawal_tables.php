<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_destinations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('asset_code', 12);
            $table->string('network_code', 12);
            $table->text('address_ciphertext');
            $table->char('address_hash', 64);
            $table->string('masked_address', 24);
            $table->string('label', 80)->nullable();
            $table->string('status', 16);
            $table->timestampsTz();
            $table->unique(['id', 'tenant_id', 'user_id', 'asset_code', 'network_code'], 'withdrawal_destination_identity_unique');
            $table->unique(['tenant_id', 'user_id', 'asset_code', 'network_code', 'address_hash'], 'withdrawal_destination_address_unique');
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'user_id', 'status']);
        });

        Schema::create('withdrawal_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('wallet_id');
            $table->uuid('withdrawal_destination_id');
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->string('asset_code', 12);
            $table->string('network_code', 12);
            $table->decimal('amount', 20, 8);
            $table->string('status', 16);
            $table->timestampTz('requested_at');
            $table->timestampTz('reviewed_at')->nullable();
            $table->uuid('reviewed_by_admin_user_id')->nullable();
            $table->string('submitted_tx_hash', 128)->nullable();
            $table->timestampTz('blockchain_confirmed_at')->nullable();
            $table->uuid('hold_ledger_entry_id')->nullable();
            $table->uuid('release_ledger_entry_id')->nullable();
            $table->uuid('settlement_ledger_entry_id')->nullable();
            $table->string('safe_review_reason', 240)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'request_id']);
            $table->unique(['id', 'tenant_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['wallet_id', 'tenant_id', 'user_id', 'asset_code'], 'withdrawal_wallet_owner_fk')->references(['id', 'tenant_id', 'user_id', 'asset_code'])->on('wallets')->restrictOnDelete();
            $table->foreign(['withdrawal_destination_id', 'tenant_id', 'user_id', 'asset_code', 'network_code'], 'withdrawal_destination_owner_fk')->references(['id', 'tenant_id', 'user_id', 'asset_code', 'network_code'])->on('withdrawal_destinations')->restrictOnDelete();
            $table->foreign('reviewed_by_admin_user_id')->references('id')->on('admin_users')->restrictOnDelete();
            $table->foreign(['hold_ledger_entry_id', 'tenant_id', 'asset_code'], 'withdrawal_hold_entry_fk')->references(['id', 'tenant_id', 'asset_code'])->on('ledger_entries')->restrictOnDelete();
            $table->foreign(['release_ledger_entry_id', 'tenant_id', 'asset_code'], 'withdrawal_release_entry_fk')->references(['id', 'tenant_id', 'asset_code'])->on('ledger_entries')->restrictOnDelete();
            $table->foreign(['settlement_ledger_entry_id', 'tenant_id', 'asset_code'], 'withdrawal_settle_entry_fk')->references(['id', 'tenant_id', 'asset_code'])->on('ledger_entries')->restrictOnDelete();
            $table->index(['tenant_id', 'user_id', 'requested_at']);
            $table->index(['tenant_id', 'status', 'requested_at']);
        });

        Schema::create('withdrawal_transaction_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('withdrawal_order_id');
            $table->string('network_code', 12);
            $table->string('tx_hash', 128);
            $table->string('verification_status', 16);
            $table->string('safe_failure_code', 64)->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['network_code', 'tx_hash']);
            $table->unique(['withdrawal_order_id', 'tx_hash']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['withdrawal_order_id', 'tenant_id'], 'withdrawal_attempt_order_fk')->references(['id', 'tenant_id'])->on('withdrawal_orders')->restrictOnDelete();
            $table->index(['tenant_id', 'verification_status']);
        });

        DB::statement("ALTER TABLE withdrawal_destinations ADD CONSTRAINT withdrawal_destination_fixed_rail CHECK (asset_code = 'USDT' AND network_code = 'TRON')");
        DB::statement("ALTER TABLE withdrawal_destinations ADD CONSTRAINT withdrawal_destination_status_check CHECK (status IN ('ACTIVE','DISABLED'))");
        DB::statement("ALTER TABLE withdrawal_destinations ADD CONSTRAINT withdrawal_destination_hash_check CHECK (address_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE withdrawal_orders ADD CONSTRAINT withdrawal_order_fixed_rail CHECK (asset_code = 'USDT' AND network_code = 'TRON')");
        DB::statement("ALTER TABLE withdrawal_orders ADD CONSTRAINT withdrawal_order_status_check CHECK (status IN ('PENDING','APPROVED','VERIFYING','SUCCEEDED','REJECTED','CANCELLED'))");
        DB::statement('ALTER TABLE withdrawal_orders ADD CONSTRAINT withdrawal_order_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE withdrawal_orders ADD CONSTRAINT withdrawal_order_request_hash_check CHECK (request_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE withdrawal_transaction_attempts ADD CONSTRAINT withdrawal_attempt_network_check CHECK (network_code = 'TRON')");
        DB::statement("ALTER TABLE withdrawal_transaction_attempts ADD CONSTRAINT withdrawal_attempt_tx_hash_check CHECK (tx_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE withdrawal_transaction_attempts ADD CONSTRAINT withdrawal_attempt_status_check CHECK (verification_status IN ('PENDING','CONFIRMED','FAILED','MISMATCH'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_withdrawal_destination_identity_mutation() RETURNS trigger AS $$
            BEGIN
                IF NEW.tenant_id <> OLD.tenant_id OR NEW.user_id <> OLD.user_id OR NEW.asset_code <> OLD.asset_code OR NEW.network_code <> OLD.network_code OR NEW.address_ciphertext <> OLD.address_ciphertext OR NEW.address_hash <> OLD.address_hash OR NEW.masked_address <> OLD.masked_address
                THEN RAISE EXCEPTION 'withdrawal destination identity is immutable' USING ERRCODE = '55000'; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION reject_withdrawal_financial_identity_mutation() RETURNS trigger AS $$
            BEGIN
                IF NEW.tenant_id <> OLD.tenant_id OR NEW.user_id <> OLD.user_id OR NEW.wallet_id <> OLD.wallet_id OR NEW.withdrawal_destination_id <> OLD.withdrawal_destination_id OR NEW.request_id <> OLD.request_id OR NEW.request_hash <> OLD.request_hash OR NEW.asset_code <> OLD.asset_code OR NEW.network_code <> OLD.network_code OR NEW.amount <> OLD.amount OR NEW.requested_at <> OLD.requested_at OR (NEW.hold_ledger_entry_id IS DISTINCT FROM OLD.hold_ledger_entry_id AND OLD.hold_ledger_entry_id IS NOT NULL)
                THEN RAISE EXCEPTION 'withdrawal financial identity is immutable' USING ERRCODE = '55000'; END IF;
                IF OLD.status IN ('SUCCEEDED','REJECTED','CANCELLED') AND NEW IS DISTINCT FROM OLD
                THEN RAISE EXCEPTION 'terminal withdrawal is immutable' USING ERRCODE = '55000'; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION validate_withdrawal_order_lifecycle() RETURNS trigger AS $$
            BEGIN
                IF NEW.status IN ('REJECTED','CANCELLED') THEN
                    IF NEW.release_ledger_entry_id IS NULL OR NEW.settlement_ledger_entry_id IS NOT NULL OR NEW.submitted_tx_hash IS NOT NULL OR NEW.blockchain_confirmed_at IS NOT NULL
                    THEN RAISE EXCEPTION 'released withdrawal has inconsistent financial state' USING ERRCODE = '23514'; END IF;
                ELSIF NEW.release_ledger_entry_id IS NOT NULL THEN
                    RAISE EXCEPTION 'active or settled withdrawal cannot have a release entry' USING ERRCODE = '23514';
                END IF;
                IF NEW.status = 'SUCCEEDED' THEN
                    IF NEW.settlement_ledger_entry_id IS NULL OR NEW.submitted_tx_hash IS NULL OR NEW.blockchain_confirmed_at IS NULL
                    THEN RAISE EXCEPTION 'successful withdrawal requires confirmed settlement evidence' USING ERRCODE = '23514'; END IF;
                ELSIF NEW.settlement_ledger_entry_id IS NOT NULL OR NEW.blockchain_confirmed_at IS NOT NULL THEN
                    RAISE EXCEPTION 'unsettled withdrawal cannot have settlement evidence' USING ERRCODE = '23514';
                END IF;
                IF NEW.status = 'VERIFYING' AND NEW.submitted_tx_hash IS NULL
                THEN RAISE EXCEPTION 'verifying withdrawal requires a transaction hash' USING ERRCODE = '23514'; END IF;
                IF NEW.status IN ('PENDING','APPROVED') AND NEW.submitted_tx_hash IS NOT NULL
                THEN RAISE EXCEPTION 'unsubmitted withdrawal cannot have a transaction hash' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION reject_withdrawal_attempt_identity_mutation() RETURNS trigger AS $$
            BEGIN
                IF NEW.tenant_id <> OLD.tenant_id OR NEW.withdrawal_order_id <> OLD.withdrawal_order_id OR NEW.network_code <> OLD.network_code OR NEW.tx_hash <> OLD.tx_hash
                THEN RAISE EXCEPTION 'withdrawal transaction attempt identity is immutable' USING ERRCODE = '55000'; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION reject_withdrawal_history_delete() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'withdrawal history cannot be deleted' USING ERRCODE = '55000';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER withdrawal_destination_identity_immutable BEFORE UPDATE ON withdrawal_destinations FOR EACH ROW EXECUTE FUNCTION reject_withdrawal_destination_identity_mutation();
            CREATE TRIGGER withdrawal_order_financial_identity_immutable BEFORE UPDATE ON withdrawal_orders FOR EACH ROW EXECUTE FUNCTION reject_withdrawal_financial_identity_mutation();
            CREATE CONSTRAINT TRIGGER withdrawal_order_lifecycle_consistent AFTER INSERT OR UPDATE ON withdrawal_orders DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_withdrawal_order_lifecycle();
            CREATE TRIGGER withdrawal_attempt_identity_immutable BEFORE UPDATE ON withdrawal_transaction_attempts FOR EACH ROW EXECUTE FUNCTION reject_withdrawal_attempt_identity_mutation();
            CREATE TRIGGER withdrawal_destination_delete_forbidden BEFORE DELETE ON withdrawal_destinations FOR EACH ROW EXECUTE FUNCTION reject_withdrawal_history_delete();
            CREATE TRIGGER withdrawal_order_delete_forbidden BEFORE DELETE ON withdrawal_orders FOR EACH ROW EXECUTE FUNCTION reject_withdrawal_history_delete();
            CREATE TRIGGER withdrawal_attempt_delete_forbidden BEFORE DELETE ON withdrawal_transaction_attempts FOR EACH ROW EXECUTE FUNCTION reject_withdrawal_history_delete();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_transaction_attempts');
        Schema::dropIfExists('withdrawal_orders');
        Schema::dropIfExists('withdrawal_destinations');
        DB::statement('DROP FUNCTION IF EXISTS reject_withdrawal_history_delete()');
        DB::statement('DROP FUNCTION IF EXISTS reject_withdrawal_attempt_identity_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS validate_withdrawal_order_lifecycle()');
        DB::statement('DROP FUNCTION IF EXISTS reject_withdrawal_financial_identity_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS reject_withdrawal_destination_identity_mutation()');
    }
};
