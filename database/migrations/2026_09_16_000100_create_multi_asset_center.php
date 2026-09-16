<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '10s'");
        DB::statement('DROP TRIGGER ledger_account_cache_from_account ON ledger_accounts');
        DB::statement('ALTER TABLE ledger_accounts ALTER COLUMN balance TYPE numeric(38,18)');
        DB::statement('CREATE CONSTRAINT TRIGGER ledger_account_cache_from_account AFTER INSERT OR UPDATE OF balance ON ledger_accounts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_ledger_account_cache_v41()');
        DB::statement('ALTER TABLE ledger_postings ALTER COLUMN delta TYPE numeric(38,18)');
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_balance_asset_scale CHECK (balance = trunc(balance, CASE asset_code WHEN 'ETH' THEN 18 WHEN 'USDC' THEN 6 ELSE 8 END))");
        DB::statement("ALTER TABLE ledger_postings ADD CONSTRAINT ledger_delta_asset_scale CHECK (delta = trunc(delta, CASE asset_code WHEN 'ETH' THEN 18 WHEN 'USDC' THEN 6 ELSE 8 END))");
        DB::statement('ALTER TABLE ledger_accounts DROP CONSTRAINT ledger_accounts_type_check');
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_check CHECK (account_type IN ('USER_AVAILABLE','USER_SECURITY_DEPOSIT','USER_WITHDRAWAL_HOLD','USER_CARD_ISSUE_HOLD','USER_CARD_FUNDING_HOLD','USER_COMMISSION','TENANT_TOPUP_CLEARING','TENANT_WITHDRAWAL_CLEARING','TENANT_CARD_FUNDING_CLEARING','TENANT_FEE_REVENUE','TENANT_COMMISSION_CLEARING','TENANT_EXCHANGE_CLEARING'))");
        DB::statement('ALTER TABLE ledger_accounts DROP CONSTRAINT ledger_accounts_negative_policy_check');
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_negative_policy_check CHECK (account_type IN ('TENANT_TOPUP_CLEARING','TENANT_WITHDRAWAL_CLEARING','TENANT_CARD_FUNDING_CLEARING','TENANT_COMMISSION_CLEARING','TENANT_EXCHANGE_CLEARING') OR balance >= 0)");
        Schema::create('asset_chain_connections', function (Blueprint $t): void {
            $t->string('network', 16)->primary();
            $t->boolean('enabled')->default(false);
            $t->text('rpc_url')->nullable();
            $t->text('credential')->nullable();
            $t->unsignedBigInteger('start_height')->nullable();
            $t->unsignedBigInteger('next_height')->nullable();
            $t->string('checkpoint_hash', 80)->nullable();
            $t->unsignedInteger('confirmations')->default(6);
            $t->timestampsTz();
        });
        foreach (['ETHEREUM', 'BITCOIN'] as $network) {
            DB::table('asset_chain_connections')->insert(['network' => $network, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('asset_rails', function (Blueprint $t): void {
            $t->string('code', 32)->primary();
            $t->string('asset_code', 12);
            $t->string('network', 16);
            $t->foreign('network')->references('network')->on('asset_chain_connections');
            $t->string('contract', 64)->nullable();
            $t->string('deposit_address', 128)->nullable();
            $t->boolean('enabled')->default(false);
            $t->timestampsTz();
        });
        foreach ([['USDT_ETHEREUM', 'USDT', 'ETHEREUM', '0xdac17f958d2ee523a2206206994597c13d831ec7'], ['USDC_ETHEREUM', 'USDC', 'ETHEREUM', '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48'], ['ETH_ETHEREUM', 'ETH', 'ETHEREUM', null], ['BTC_BITCOIN', 'BTC', 'BITCOIN', null]] as [$code, $asset, $network, $contract]) {
            DB::table('asset_rails')->insert(['code' => $code, 'asset_code' => $asset, 'network' => $network, 'contract' => $contract, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('asset_company_rails', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->string('rail_code', 32);
            $t->foreign('rail_code')->references('code')->on('asset_rails');
            $t->boolean('deposit_enabled')->default(false);
            $t->boolean('withdrawal_enabled')->default(false);
            $t->decimal('withdrawal_fee', 38, 18)->nullable();
            $t->decimal('minimum_deposit', 38, 18)->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'rail_code']);
        });
        Schema::create('asset_exchange_policies', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->string('asset_code', 12);
            $t->boolean('enabled')->default(false);
            $t->decimal('fee_percent', 12, 8)->nullable();
            $t->decimal('single_limit', 20, 8)->nullable();
            $t->decimal('daily_limit', 20, 8)->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'asset_code']);
        });
        DB::statement("ALTER TABLE asset_exchange_policies ADD CHECK (asset_code IN ('USDC','ETH','BTC') AND (fee_percent IS NULL OR (fee_percent >= 0 AND fee_percent < 100)) AND (single_limit IS NULL OR single_limit > 0) AND (daily_limit IS NULL OR daily_limit > 0))");
        Schema::create('asset_market_settings', function (Blueprint $t): void {
            $t->unsignedInteger('id')->primary();
            $t->boolean('enabled')->default(false);
            $t->text('api_key')->nullable();
            $t->timestampsTz();
        });
        DB::table('asset_market_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('asset_market_snapshots', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('provider', 32);
            $t->jsonb('usd_prices');
            $t->timestampTz('observed_at');
            $t->timestampTz('created_at')->useCurrent();
        });
        Schema::create('asset_exchange_orders', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->uuid('user_id');
            $t->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            $t->uuid('request_id');
            $t->string('asset_code', 12);
            $t->decimal('amount', 38, 18);
            $t->decimal('rate', 38, 18);
            $t->decimal('gross_amount', 20, 8);
            $t->decimal('fee_amount', 20, 8);
            $t->decimal('receive_amount', 20, 8);
            $t->decimal('fee_percent', 12, 8);
            $t->foreignUuid('snapshot_id')->constrained('asset_market_snapshots');
            $t->timestampTz('expires_at');
            $t->string('status', 20)->default('QUOTED');
            $t->foreignUuid('source_entry_id')->nullable()->constrained('ledger_entries');
            $t->foreignUuid('target_entry_id')->nullable()->constrained('ledger_entries');
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'user_id', 'request_id']);
            $t->index(['tenant_id', 'user_id', 'completed_at']);
        });
        DB::statement("ALTER TABLE asset_exchange_orders ADD CHECK (asset_code IN ('USDC','ETH','BTC') AND amount > 0 AND rate > 0 AND gross_amount > 0 AND fee_amount >= 0 AND receive_amount > 0 AND gross_amount = fee_amount + receive_amount AND status IN ('QUOTED','COMPLETED') AND ((status = 'COMPLETED') = (source_entry_id IS NOT NULL AND target_entry_id IS NOT NULL AND completed_at IS NOT NULL)))");
        foreach (['asset_deposit_orders', 'asset_withdrawal_orders'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table): void {
                $t->uuid('id')->primary();
                $t->foreignUuid('tenant_id')->constrained('tenants');
                $t->uuid('user_id');
                $t->uuid('wallet_id');
                $t->string('asset_code', 12);
                $t->foreign(['wallet_id', 'tenant_id', 'user_id', 'asset_code'])->references(['id', 'tenant_id', 'user_id', 'asset_code'])->on('wallets');
                $t->string('rail_code', 32);
                $t->foreign('rail_code')->references('code')->on('asset_rails');
                $t->string('network', 16);
                $t->string('contract', 64)->nullable();
                $t->text('address');
                $t->string('address_hash', 64);
                $t->uuid('request_id');
                $t->string('request_hash', 64);
                $t->decimal('amount', 38, 18);
                $t->string('status', 24)->default('PENDING');
                $t->string('chain_event_id', 200)->nullable();
                $t->foreignUuid('ledger_entry_id')->nullable()->constrained('ledger_entries');
                $t->timestampsTz();
                $t->unique(['tenant_id', 'user_id', 'request_id']);
                $t->unique(['network', 'chain_event_id']);
                if ($table === 'asset_deposit_orders') {
                    $t->decimal('requested_amount', 38, 18);
                    $t->timestampTz('expires_at');
                    $t->foreignUuid('manual_confirmed_by')->nullable()->constrained('admin_users');
                    $t->timestampTz('manual_confirmed_at')->nullable();
                    $t->uuid('manual_request_id')->nullable();
                    $t->unique(['manual_confirmed_by', 'manual_request_id']);
                    // Reservations remain permanent: never reassign a delayed transfer.
                    $t->unique(['rail_code', 'address_hash', 'amount']);
                } else {
                    $t->decimal('fee_amount', 38, 18);
                    $t->foreignUuid('hold_entry_id')->nullable()->constrained('ledger_entries');
                    $t->string('submitted_tx_hash', 128)->nullable();
                    $t->foreignUuid('reviewed_by')->nullable()->constrained('admin_users');
                    $t->timestampTz('reviewed_at')->nullable();
                }
            });
            DB::statement("ALTER TABLE {$table} ADD CHECK (amount > 0 AND amount = trunc(amount, CASE asset_code WHEN 'ETH' THEN 18 WHEN 'USDC' THEN 6 ELSE 8 END))");
        }
        Schema::create('asset_chain_observations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('network', 16);
            $t->string('event_id', 200);
            $t->string('rail_code', 32);
            $t->string('address', 128);
            $t->string('amount', 64);
            $t->unsignedBigInteger('block_height');
            $t->string('block_hash', 80);
            $t->timestampTz('occurred_at');
            $t->string('status', 24)->default('UNMATCHED');
            $t->uuid('order_id')->nullable();
            $t->timestampsTz();
            $t->unique(['network', 'event_id']);
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_asset_order() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE field text;
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'asset order history is immutable'; END IF;
                IF TG_TABLE_NAME = 'asset_exchange_orders' THEN
                    IF (to_jsonb(NEW) - ARRAY['status','source_entry_id','target_entry_id','completed_at','updated_at']) IS DISTINCT FROM
                       (to_jsonb(OLD) - ARRAY['status','source_entry_id','target_entry_id','completed_at','updated_at'])
                       OR OLD.status = 'COMPLETED' THEN RAISE EXCEPTION 'exchange economics and completion are immutable'; END IF;
                ELSE
                    IF (to_jsonb(NEW) - ARRAY['status','chain_event_id','ledger_entry_id','updated_at','manual_confirmed_by','manual_confirmed_at','manual_request_id','hold_entry_id','submitted_tx_hash','reviewed_by','reviewed_at']) IS DISTINCT FROM
                       (to_jsonb(OLD) - ARRAY['status','chain_event_id','ledger_entry_id','updated_at','manual_confirmed_by','manual_confirmed_at','manual_request_id','hold_entry_id','submitted_tx_hash','reviewed_by','reviewed_at'])
                       OR OLD.status IN ('CREDITED','COMPLETED','CANCELLED','REJECTED') THEN RAISE EXCEPTION 'asset order identity and terminal facts are immutable'; END IF;
                END IF;
                FOREACH field IN ARRAY ARRAY['submitted_tx_hash','hold_entry_id','reviewed_by','reviewed_at'] LOOP
                    IF to_jsonb(OLD)->>field IS NOT NULL AND (to_jsonb(NEW)->>field) IS DISTINCT FROM (to_jsonb(OLD)->>field) THEN
                        RAISE EXCEPTION 'recorded financial provenance is immutable';
                    END IF;
                END LOOP;
                RETURN NEW;
            END; $$;
            CREATE OR REPLACE FUNCTION protect_asset_observation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' OR (to_jsonb(NEW) - ARRAY['status','updated_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','updated_at']) THEN
                    RAISE EXCEPTION 'chain evidence is immutable';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER asset_observation_immutable BEFORE UPDATE OR DELETE ON asset_chain_observations FOR EACH ROW EXECUTE FUNCTION protect_asset_observation();
            CREATE TRIGGER exchange_order_immutable BEFORE UPDATE OR DELETE ON asset_exchange_orders FOR EACH ROW EXECUTE FUNCTION protect_asset_order();
            CREATE TRIGGER deposit_order_immutable BEFORE UPDATE OR DELETE ON asset_deposit_orders FOR EACH ROW EXECUTE FUNCTION protect_asset_order();
            CREATE TRIGGER withdrawal_order_immutable BEFORE UPDATE OR DELETE ON asset_withdrawal_orders FOR EACH ROW EXECUTE FUNCTION protect_asset_order();
            CREATE TRIGGER market_snapshot_immutable BEFORE UPDATE OR DELETE ON asset_market_snapshots FOR EACH ROW EXECUTE FUNCTION reject_ledger_history_mutation();
            ALTER TABLE asset_deposit_orders ADD CHECK (status IN ('PENDING','CONFIRMING','REQUIRES_REVIEW','EXPIRED','CREDITED'));
            ALTER TABLE asset_deposit_orders ADD CHECK ((manual_confirmed_by IS NULL AND manual_confirmed_at IS NULL AND manual_request_id IS NULL) OR (manual_confirmed_by IS NOT NULL AND manual_confirmed_at IS NOT NULL AND manual_request_id IS NOT NULL AND status = 'CREDITED'));
            ALTER TABLE asset_deposit_orders ADD CHECK ((status = 'CREDITED') = (ledger_entry_id IS NOT NULL));
            ALTER TABLE asset_withdrawal_orders ADD CHECK (status IN ('PENDING','APPROVED','PROCESSING','UNKNOWN','COMPLETED','CANCELLED','REJECTED'));
            ALTER TABLE asset_withdrawal_orders ADD CHECK (fee_amount >= 0 AND fee_amount < amount AND fee_amount = trunc(fee_amount, CASE asset_code WHEN 'ETH' THEN 18 WHEN 'USDC' THEN 6 ELSE 8 END));
            ALTER TABLE asset_exchange_orders ADD CHECK (amount = trunc(amount, CASE asset_code WHEN 'ETH' THEN 18 WHEN 'USDC' THEN 6 ELSE 8 END));
            ALTER TABLE asset_company_rails ADD CHECK ((withdrawal_fee IS NULL OR withdrawal_fee >= 0) AND (minimum_deposit IS NULL OR minimum_deposit > 0));
            ALTER TABLE asset_withdrawal_orders ADD CHECK (status <> 'COMPLETED' OR (chain_event_id IS NOT NULL AND ledger_entry_id IS NOT NULL));
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Multi-asset financial history cannot be destructively downgraded.');
    }
};
