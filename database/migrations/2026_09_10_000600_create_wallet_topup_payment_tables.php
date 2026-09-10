<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_topup_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('wallet_id');
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->string('asset_code', 12);
            $table->decimal('amount', 20, 8);
            $table->string('status', 24);
            $table->string('payment_provider', 64);
            $table->string('provider_transaction_id', 180)->nullable();
            $table->uuid('ledger_entry_id')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('credited_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'request_id']);
            $table->unique(['id', 'tenant_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['wallet_id', 'tenant_id', 'user_id', 'asset_code'], 'topup_wallet_owner_fk')
                ->references(['id', 'tenant_id', 'user_id', 'asset_code'])->on('wallets')->restrictOnDelete();
            $table->foreign(['ledger_entry_id', 'tenant_id', 'asset_code'], 'topup_ledger_entry_fk')
                ->references(['id', 'tenant_id', 'asset_code'])->on('ledger_entries')->restrictOnDelete();
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['tenant_id', 'status', 'updated_at']);
        });

        Schema::create('payment_provider_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('wallet_topup_order_id');
            $table->string('provider', 64);
            $table->string('provider_request_id', 180);
            $table->string('provider_transaction_id', 180)->nullable();
            $table->string('status', 24);
            $table->string('asset_code', 12);
            $table->decimal('amount', 20, 8);
            $table->timestampTz('last_queried_at')->nullable();
            $table->timestampsTz();

            $table->unique('wallet_topup_order_id');
            $table->unique(['provider', 'provider_request_id']);
            $table->unique(['id', 'tenant_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['wallet_topup_order_id', 'tenant_id'], 'payment_transaction_order_fk')
                ->references(['id', 'tenant_id'])->on('wallet_topup_orders')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'updated_at']);
        });
        DB::statement('CREATE UNIQUE INDEX payment_provider_transaction_reference_unique ON payment_provider_transactions (provider, provider_transaction_id) WHERE provider_transaction_id IS NOT NULL');

        Schema::create('payment_provider_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('payment_provider_transaction_id');
            $table->string('provider', 64);
            $table->string('provider_event_key', 180);
            $table->string('event_type', 80);
            $table->string('provider_transaction_id', 180)->nullable();
            $table->string('provider_request_id', 180)->nullable();
            $table->string('normalized_status', 24)->nullable();
            $table->string('asset_code', 12)->nullable();
            $table->decimal('amount', 20, 8)->nullable();
            $table->char('payload_digest', 64);
            $table->string('processing_status', 24);
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_event_key']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['payment_provider_transaction_id', 'tenant_id'], 'payment_event_transaction_fk')
                ->references(['id', 'tenant_id'])->on('payment_provider_transactions')->restrictOnDelete();
            $table->index(['tenant_id', 'processing_status', 'received_at']);
        });

        DB::statement("ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_status_check CHECK (status IN ('PENDING','PROCESSING','PAID','CREDITED','FAILED','CANCELLED','EXPIRED','REFUNDED'))");
        DB::statement("ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_asset_check CHECK (asset_code ~ '^[A-Z0-9]{3,12}$')");
        DB::statement('ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_request_hash_check CHECK (request_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE payment_provider_transactions ADD CONSTRAINT payment_transaction_status_check CHECK (status IN ('PENDING','PROCESSING','SUCCEEDED','FAILED','UNKNOWN'))");
        DB::statement("ALTER TABLE payment_provider_transactions ADD CONSTRAINT payment_transaction_asset_check CHECK (asset_code ~ '^[A-Z0-9]{3,12}$')");
        DB::statement('ALTER TABLE payment_provider_transactions ADD CONSTRAINT payment_transaction_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE payment_provider_events ADD CONSTRAINT payment_event_status_check CHECK (normalized_status IS NULL OR normalized_status IN ('PENDING','PROCESSING','SUCCEEDED','FAILED','UNKNOWN'))");
        DB::statement("ALTER TABLE payment_provider_events ADD CONSTRAINT payment_event_processing_check CHECK (processing_status IN ('PENDING','PROCESSING','PROCESSED','FAILED','REQUIRES_REVIEW'))");
        DB::statement("ALTER TABLE payment_provider_events ADD CONSTRAINT payment_event_asset_check CHECK (asset_code IS NULL OR asset_code ~ '^[A-Z0-9]{3,12}$')");
        DB::statement('ALTER TABLE payment_provider_events ADD CONSTRAINT payment_event_amount_check CHECK (amount IS NULL OR amount > 0)');
        DB::statement("ALTER TABLE payment_provider_events ADD CONSTRAINT payment_event_digest_check CHECK (payload_digest ~ '^[a-f0-9]{64}$')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_topup_identity_mutation() RETURNS trigger AS $$
            BEGIN
                IF (
                    NEW.tenant_id <> OLD.tenant_id OR NEW.user_id <> OLD.user_id OR NEW.wallet_id <> OLD.wallet_id OR
                    NEW.request_id <> OLD.request_id OR NEW.request_hash <> OLD.request_hash OR NEW.asset_code <> OLD.asset_code OR
                    NEW.amount <> OLD.amount OR NEW.payment_provider <> OLD.payment_provider
                ) THEN RAISE EXCEPTION 'top-up financial identity is immutable' USING ERRCODE = '55000'; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION reject_payment_transaction_identity_mutation() RETURNS trigger AS $$
            BEGIN
                IF (
                    NEW.tenant_id <> OLD.tenant_id OR NEW.wallet_topup_order_id <> OLD.wallet_topup_order_id OR
                    NEW.provider <> OLD.provider OR NEW.provider_request_id <> OLD.provider_request_id OR
                    NEW.asset_code <> OLD.asset_code OR NEW.amount <> OLD.amount
                ) THEN RAISE EXCEPTION 'payment transaction identity is immutable' USING ERRCODE = '55000'; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION reject_payment_event_fact_mutation() RETURNS trigger AS $$
            BEGIN
                IF (
                    NEW.tenant_id <> OLD.tenant_id OR NEW.payment_provider_transaction_id <> OLD.payment_provider_transaction_id OR
                    NEW.provider <> OLD.provider OR NEW.provider_event_key <> OLD.provider_event_key OR NEW.event_type <> OLD.event_type OR
                    NEW.provider_transaction_id IS DISTINCT FROM OLD.provider_transaction_id OR NEW.provider_request_id IS DISTINCT FROM OLD.provider_request_id OR
                    NEW.normalized_status IS DISTINCT FROM OLD.normalized_status OR NEW.asset_code IS DISTINCT FROM OLD.asset_code OR
                    NEW.amount IS DISTINCT FROM OLD.amount OR NEW.payload_digest <> OLD.payload_digest OR NEW.received_at <> OLD.received_at
                ) THEN RAISE EXCEPTION 'payment event facts are immutable' USING ERRCODE = '55000'; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER wallet_topup_identity_immutable BEFORE UPDATE ON wallet_topup_orders FOR EACH ROW EXECUTE FUNCTION reject_topup_identity_mutation();
            CREATE TRIGGER payment_transaction_identity_immutable BEFORE UPDATE ON payment_provider_transactions FOR EACH ROW EXECUTE FUNCTION reject_payment_transaction_identity_mutation();
            CREATE TRIGGER payment_event_facts_immutable BEFORE UPDATE ON payment_provider_events FOR EACH ROW EXECUTE FUNCTION reject_payment_event_fact_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_events');
        Schema::dropIfExists('payment_provider_transactions');
        Schema::dropIfExists('wallet_topup_orders');
        DB::statement('DROP FUNCTION IF EXISTS reject_topup_identity_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS reject_payment_transaction_identity_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS reject_payment_event_fact_mutation()');
    }
};
