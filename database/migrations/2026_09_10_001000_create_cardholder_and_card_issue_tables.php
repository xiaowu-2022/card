<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('legal_first_name', 40)->nullable();
            $table->string('legal_last_name', 40)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->char('nationality_country_code', 2)->nullable();
            $table->string('residential_address', 100)->nullable();
            $table->string('residential_city', 50)->nullable();
            $table->string('residential_state', 50)->nullable();
            $table->char('residential_country_code', 2)->nullable();
            $table->string('residential_postal_code', 10)->nullable();
        });
        DB::statement("ALTER TABLE user_profiles ADD CONSTRAINT user_profiles_card_setup_country_check CHECK (
            (nationality_country_code IS NULL OR nationality_country_code ~ '^[A-Z]{2}$')
            AND (residential_country_code IS NULL OR residential_country_code ~ '^[A-Z]{2}$')
        )");
        DB::statement('ALTER TABLE user_profiles ADD CONSTRAINT user_profiles_card_setup_complete_check CHECK (
            (legal_first_name IS NULL AND legal_last_name IS NULL AND date_of_birth IS NULL AND nationality_country_code IS NULL
             AND residential_address IS NULL AND residential_city IS NULL AND residential_state IS NULL
             AND residential_country_code IS NULL AND residential_postal_code IS NULL)
            OR
            (legal_first_name IS NOT NULL AND legal_last_name IS NOT NULL AND date_of_birth IS NOT NULL AND nationality_country_code IS NOT NULL
             AND residential_address IS NOT NULL AND residential_city IS NOT NULL AND residential_state IS NOT NULL
             AND residential_country_code IS NOT NULL AND residential_postal_code IS NOT NULL)
        )');

        Schema::table('tenant_card_product_configs', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id', 'card_product_id'], 'tenant_card_product_config_scope_unique');
        });

        Schema::create('provider_cardholders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('provider', 32);
            $table->string('provider_cardholder_id', 180)->nullable();
            $table->string('status', 24);
            $table->string('provider_status', 32)->nullable();
            $table->string('provider_review_status', 32)->nullable();
            $table->string('safe_reason', 240)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id', 'provider']);
            $table->unique(['id', 'tenant_id', 'user_id', 'provider'], 'provider_cardholder_scope_unique');
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'updated_at']);
        });
        DB::statement('CREATE UNIQUE INDEX provider_cardholder_external_unique ON provider_cardholders (provider, provider_cardholder_id) WHERE provider_cardholder_id IS NOT NULL');
        DB::statement("ALTER TABLE provider_cardholders ADD CONSTRAINT provider_cardholders_provider_check CHECK (provider = 'PHOTONPAY')");
        DB::statement("ALTER TABLE provider_cardholders ADD CONSTRAINT provider_cardholders_status_check CHECK (status IN ('SUBMITTING','PENDING','READY','ACTION_REQUIRED','REJECTED','DISABLED','UNKNOWN'))");

        Schema::create('card_issue_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('wallet_id');
            $table->uuid('card_product_id');
            $table->uuid('tenant_card_product_config_id');
            $table->uuid('provider_cardholder_id');
            $table->uuid('request_id');
            $table->char('request_hash', 64);
            $table->decimal('opening_fee', 20, 8);
            $table->decimal('minimum_initial_load', 20, 8);
            $table->decimal('initial_load_amount', 20, 8);
            $table->string('wallet_asset', 12);
            $table->string('card_currency', 12);
            $table->string('provider', 32);
            $table->string('provider_product_ref', 64);
            $table->string('provider_request_id', 180);
            $table->string('provider_card_id', 180)->nullable();
            $table->string('status', 24);
            $table->uuid('fee_hold_ledger_entry_id')->nullable();
            $table->uuid('funding_hold_ledger_entry_id')->nullable();
            $table->uuid('fee_settlement_ledger_entry_id')->nullable();
            $table->uuid('funding_settlement_ledger_entry_id')->nullable();
            $table->uuid('fee_release_ledger_entry_id')->nullable();
            $table->uuid('funding_release_ledger_entry_id')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('processing_at')->nullable();
            $table->timestampTz('succeeded_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id', 'request_id']);
            $table->unique(['provider', 'provider_request_id']);
            $table->unique(['id', 'tenant_id', 'user_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['wallet_id', 'tenant_id', 'user_id', 'wallet_asset'], 'card_issue_wallet_owner_fk')
                ->references(['id', 'tenant_id', 'user_id', 'asset_code'])->on('wallets')->restrictOnDelete();
            $table->foreign('card_product_id')->references('id')->on('card_products')->restrictOnDelete();
            $table->foreign(['tenant_card_product_config_id', 'tenant_id', 'card_product_id'], 'card_issue_product_config_fk')
                ->references(['id', 'tenant_id', 'card_product_id'])->on('tenant_card_product_configs')->restrictOnDelete();
            $table->foreign(['provider_cardholder_id', 'tenant_id', 'user_id', 'provider'], 'card_issue_cardholder_fk')
                ->references(['id', 'tenant_id', 'user_id', 'provider'])->on('provider_cardholders')->restrictOnDelete();
            foreach (['fee_hold', 'funding_hold', 'fee_settlement', 'funding_settlement', 'fee_release', 'funding_release'] as $name) {
                $table->foreign(["{$name}_ledger_entry_id", 'tenant_id', 'wallet_asset'], "card_issue_{$name}_entry_fk")
                    ->references(['id', 'tenant_id', 'asset_code'])->on('ledger_entries')->restrictOnDelete();
            }
            $table->index(['tenant_id', 'user_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'status', 'updated_at']);
        });
        DB::statement("ALTER TABLE card_issue_orders ADD CONSTRAINT card_issue_fixed_identity_check CHECK (
            provider = 'PHOTONPAY' AND wallet_asset = 'USDT' AND card_currency = 'USD'
        )");
        DB::statement("ALTER TABLE card_issue_orders ADD CONSTRAINT card_issue_status_check CHECK (status IN ('PROCESSING','SUCCEEDED','FAILED','UNKNOWN'))");
        DB::statement('ALTER TABLE card_issue_orders ADD CONSTRAINT card_issue_money_check CHECK (
            opening_fee >= 0 AND minimum_initial_load >= 20 AND initial_load_amount >= minimum_initial_load
        )');
        DB::statement("ALTER TABLE card_issue_orders ADD CONSTRAINT card_issue_request_hash_check CHECK (request_hash ~ '^[a-f0-9]{64}$')");

        Schema::create('user_cards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('card_product_id');
            $table->uuid('card_issue_order_id');
            $table->uuid('provider_cardholder_id');
            $table->string('provider', 32);
            $table->string('provider_card_id', 180);
            $table->string('card_currency', 12);
            $table->string('masked_pan', 32);
            $table->char('last4', 4);
            $table->string('expiry', 7)->nullable();
            $table->string('provider_status', 32);
            $table->decimal('provider_balance', 20, 8)->nullable();
            $table->timestampTz('provider_balance_synced_at')->nullable();
            $table->timestampsTz();

            $table->unique('card_issue_order_id');
            $table->unique(['provider', 'provider_card_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign('card_product_id')->references('id')->on('card_products')->restrictOnDelete();
            $table->foreign(['card_issue_order_id', 'tenant_id', 'user_id'], 'user_card_issue_order_fk')
                ->references(['id', 'tenant_id', 'user_id'])->on('card_issue_orders')->restrictOnDelete();
            $table->foreign(['provider_cardholder_id', 'tenant_id', 'user_id', 'provider'], 'user_card_cardholder_fk')
                ->references(['id', 'tenant_id', 'user_id', 'provider'])->on('provider_cardholders')->restrictOnDelete();
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['tenant_id', 'provider_status']);
        });
        DB::statement("ALTER TABLE user_cards ADD CONSTRAINT user_cards_safe_identity_check CHECK (
            provider = 'PHOTONPAY' AND card_currency = 'USD' AND last4 ~ '^[0-9]{4}$'
            AND masked_pan !~ '^[0-9]{12,19}$'
        )");
        DB::statement('ALTER TABLE user_cards ADD CONSTRAINT user_cards_balance_check CHECK (provider_balance IS NULL OR provider_balance >= 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_card_issue_identity_v10() RETURNS trigger AS $$
            BEGIN
                IF NEW.tenant_id <> OLD.tenant_id OR NEW.user_id <> OLD.user_id OR NEW.wallet_id <> OLD.wallet_id
                   OR NEW.card_product_id <> OLD.card_product_id OR NEW.tenant_card_product_config_id <> OLD.tenant_card_product_config_id
                   OR NEW.provider_cardholder_id <> OLD.provider_cardholder_id OR NEW.request_id <> OLD.request_id
                   OR NEW.request_hash <> OLD.request_hash OR NEW.opening_fee <> OLD.opening_fee
                   OR NEW.minimum_initial_load <> OLD.minimum_initial_load OR NEW.initial_load_amount <> OLD.initial_load_amount
                   OR NEW.wallet_asset <> OLD.wallet_asset OR NEW.card_currency <> OLD.card_currency OR NEW.provider <> OLD.provider
                   OR NEW.provider_product_ref <> OLD.provider_product_ref OR NEW.provider_request_id <> OLD.provider_request_id THEN
                    RAISE EXCEPTION 'card issue financial identity is immutable' USING ERRCODE = '55000';
                END IF;
                IF OLD.status IN ('SUCCEEDED','FAILED') AND NEW.status <> OLD.status THEN
                    RAISE EXCEPTION 'terminal card issue status is immutable' USING ERRCODE = '55000';
                END IF;
                IF (OLD.provider_card_id IS NOT NULL AND NEW.provider_card_id IS DISTINCT FROM OLD.provider_card_id)
                   OR (OLD.fee_hold_ledger_entry_id IS NOT NULL AND NEW.fee_hold_ledger_entry_id IS DISTINCT FROM OLD.fee_hold_ledger_entry_id)
                   OR (OLD.funding_hold_ledger_entry_id IS NOT NULL AND NEW.funding_hold_ledger_entry_id IS DISTINCT FROM OLD.funding_hold_ledger_entry_id)
                   OR (OLD.fee_settlement_ledger_entry_id IS NOT NULL AND NEW.fee_settlement_ledger_entry_id IS DISTINCT FROM OLD.fee_settlement_ledger_entry_id)
                   OR (OLD.funding_settlement_ledger_entry_id IS NOT NULL AND NEW.funding_settlement_ledger_entry_id IS DISTINCT FROM OLD.funding_settlement_ledger_entry_id)
                   OR (OLD.fee_release_ledger_entry_id IS NOT NULL AND NEW.fee_release_ledger_entry_id IS DISTINCT FROM OLD.fee_release_ledger_entry_id)
                   OR (OLD.funding_release_ledger_entry_id IS NOT NULL AND NEW.funding_release_ledger_entry_id IS DISTINCT FROM OLD.funding_release_ledger_entry_id) THEN
                    RAISE EXCEPTION 'card issue result references are immutable once set' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER card_issue_identity_immutable BEFORE UPDATE ON card_issue_orders
            FOR EACH ROW EXECUTE FUNCTION protect_card_issue_identity_v10();

            CREATE OR REPLACE FUNCTION validate_card_issue_financial_state_v10() RETURNS trigger AS $$
            DECLARE
                current_state card_issue_orders%ROWTYPE;
            BEGIN
                SELECT * INTO current_state FROM card_issue_orders WHERE id = NEW.id;
                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;
                IF current_state.funding_hold_ledger_entry_id IS NULL
                   OR (current_state.opening_fee = 0 AND current_state.fee_hold_ledger_entry_id IS NOT NULL)
                   OR (current_state.opening_fee > 0 AND current_state.fee_hold_ledger_entry_id IS NULL) THEN
                    RAISE EXCEPTION 'card issue holds are incomplete' USING ERRCODE = '23514';
                END IF;
                IF current_state.status IN ('PROCESSING','UNKNOWN') THEN
                    IF current_state.succeeded_at IS NOT NULL OR current_state.failed_at IS NOT NULL OR current_state.provider_card_id IS NOT NULL
                       OR current_state.fee_settlement_ledger_entry_id IS NOT NULL OR current_state.funding_settlement_ledger_entry_id IS NOT NULL
                       OR current_state.fee_release_ledger_entry_id IS NOT NULL OR current_state.funding_release_ledger_entry_id IS NOT NULL THEN
                        RAISE EXCEPTION 'unresolved card issue has terminal fields' USING ERRCODE = '23514';
                    END IF;
                    IF EXISTS (SELECT 1 FROM user_cards WHERE card_issue_order_id = current_state.id) THEN
                        RAISE EXCEPTION 'unresolved card issue cannot have a local card' USING ERRCODE = '23514';
                    END IF;
                ELSIF current_state.status = 'SUCCEEDED' THEN
                    IF current_state.succeeded_at IS NULL OR current_state.failed_at IS NOT NULL OR current_state.provider_card_id IS NULL
                       OR current_state.funding_settlement_ledger_entry_id IS NULL OR current_state.funding_release_ledger_entry_id IS NOT NULL
                       OR current_state.fee_release_ledger_entry_id IS NOT NULL
                       OR (current_state.opening_fee = 0 AND current_state.fee_settlement_ledger_entry_id IS NOT NULL)
                       OR (current_state.opening_fee > 0 AND current_state.fee_settlement_ledger_entry_id IS NULL) THEN
                        RAISE EXCEPTION 'successful card issue settlement is incomplete' USING ERRCODE = '23514';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM user_cards WHERE card_issue_order_id = current_state.id) THEN
                        RAISE EXCEPTION 'successful card issue requires exactly one local card' USING ERRCODE = '23514';
                    END IF;
                ELSIF current_state.status = 'FAILED' THEN
                    IF current_state.failed_at IS NULL OR current_state.succeeded_at IS NOT NULL OR current_state.provider_card_id IS NOT NULL
                       OR current_state.funding_release_ledger_entry_id IS NULL OR current_state.funding_settlement_ledger_entry_id IS NOT NULL
                       OR current_state.fee_settlement_ledger_entry_id IS NOT NULL
                       OR (current_state.opening_fee = 0 AND current_state.fee_release_ledger_entry_id IS NOT NULL)
                       OR (current_state.opening_fee > 0 AND current_state.fee_release_ledger_entry_id IS NULL) THEN
                        RAISE EXCEPTION 'failed card issue release is incomplete' USING ERRCODE = '23514';
                    END IF;
                    IF EXISTS (SELECT 1 FROM user_cards WHERE card_issue_order_id = current_state.id) THEN
                        RAISE EXCEPTION 'failed card issue cannot have a local card' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER card_issue_financial_state_complete
            AFTER INSERT OR UPDATE ON card_issue_orders DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION validate_card_issue_financial_state_v10();

            CREATE OR REPLACE FUNCTION validate_user_card_issue_state_v10() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM card_issue_orders
                    WHERE id = NEW.card_issue_order_id
                      AND tenant_id = NEW.tenant_id
                      AND user_id = NEW.user_id
                      AND card_product_id = NEW.card_product_id
                      AND provider_cardholder_id = NEW.provider_cardholder_id
                      AND provider = NEW.provider
                      AND provider_card_id = NEW.provider_card_id
                      AND card_currency = NEW.card_currency
                      AND status = 'SUCCEEDED'
                ) THEN
                    RAISE EXCEPTION 'user card identity requires its exact successful issue order' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER user_card_successful_issue
            AFTER INSERT OR UPDATE ON user_cards DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION validate_user_card_issue_state_v10();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_cards');
        Schema::dropIfExists('card_issue_orders');
        Schema::dropIfExists('provider_cardholders');
        DB::statement('DROP FUNCTION IF EXISTS validate_card_issue_financial_state_v10()');
        DB::statement('DROP FUNCTION IF EXISTS validate_user_card_issue_state_v10()');
        DB::statement('DROP FUNCTION IF EXISTS protect_card_issue_identity_v10()');
        Schema::table('tenant_card_product_configs', function (Blueprint $table): void {
            $table->dropUnique('tenant_card_product_config_scope_unique');
        });
        DB::statement('ALTER TABLE user_profiles DROP CONSTRAINT IF EXISTS user_profiles_card_setup_complete_check');
        DB::statement('ALTER TABLE user_profiles DROP CONSTRAINT IF EXISTS user_profiles_card_setup_country_check');
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'legal_first_name', 'legal_last_name', 'date_of_birth', 'nationality_country_code',
                'residential_address', 'residential_city', 'residential_state', 'residential_country_code', 'residential_postal_code',
            ]);
        });
    }
};
