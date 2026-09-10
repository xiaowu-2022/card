<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_topup_orders', function (Blueprint $table): void {
            $table->string('payment_rail', 32)->nullable();
            $table->decimal('requested_amount', 20, 8)->nullable();
            $table->decimal('expected_amount', 20, 8)->nullable();
            $table->decimal('identification_increment', 20, 8)->nullable();
            $table->string('network_code', 16)->nullable();
            $table->string('deposit_address', 128)->nullable();
            $table->string('token_contract', 128)->nullable();
            $table->char('matched_tx_hash', 64)->nullable();
            $table->unsignedInteger('matched_transfer_index')->nullable();
            $table->timestampTz('blockchain_detected_at')->nullable();
            $table->timestampTz('blockchain_confirmed_at')->nullable();
        });

        DB::statement("ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_trc20_identity_check CHECK (
            (payment_rail IS NULL AND requested_amount IS NULL AND expected_amount IS NULL
                AND identification_increment IS NULL AND network_code IS NULL AND deposit_address IS NULL
                AND token_contract IS NULL AND matched_tx_hash IS NULL AND matched_transfer_index IS NULL
                AND blockchain_detected_at IS NULL AND blockchain_confirmed_at IS NULL)
            OR (
                payment_rail = 'TRC20_SHARED' AND asset_code = 'USDT' AND network_code = 'TRON'
                AND requested_amount IS NOT NULL AND requested_amount > 0 AND requested_amount = trunc(requested_amount, 2)
                AND identification_increment BETWEEN 0.01 AND 0.99
                AND identification_increment = trunc(identification_increment, 2)
                AND expected_amount = requested_amount + identification_increment
                AND amount = expected_amount AND deposit_address IS NOT NULL AND token_contract IS NOT NULL
                AND deposit_address ~ '^T[1-9A-HJ-NP-Za-km-z]{33}$'
                AND token_contract ~ '^T[1-9A-HJ-NP-Za-km-z]{33}$'
                AND expires_at IS NOT NULL AND expires_at > created_at
            )
        )");
        DB::statement("ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_trc20_match_check CHECK (
            payment_rail IS NULL OR (
                (matched_tx_hash IS NULL AND matched_transfer_index IS NULL AND blockchain_detected_at IS NULL AND blockchain_confirmed_at IS NULL)
                OR (matched_tx_hash IS NOT NULL AND matched_transfer_index IS NOT NULL AND blockchain_detected_at IS NOT NULL
                    AND matched_tx_hash ~ '^[a-f0-9]{64}$'
                    AND (blockchain_confirmed_at IS NULL OR (status IN ('PAID','CREDITED') AND blockchain_confirmed_at >= blockchain_detected_at)))
            )
        )");
        DB::statement("ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_trc20_detection_state_check CHECK (
            payment_rail IS NULL OR (status <> 'PENDING' OR matched_tx_hash IS NULL)
                AND (status <> 'PROCESSING' OR matched_tx_hash IS NOT NULL)
        )");
        DB::statement("ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_trc20_paid_check CHECK (
            payment_rail IS NULL OR status NOT IN ('PAID','CREDITED') OR blockchain_confirmed_at IS NOT NULL
        )");
        DB::statement("ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_trc20_expired_check CHECK (
            payment_rail IS NULL OR status <> 'EXPIRED' OR matched_tx_hash IS NULL
        )");
        DB::statement("CREATE UNIQUE INDEX topup_trc20_active_amount_unique ON wallet_topup_orders (deposit_address, expected_amount)
            WHERE payment_rail = 'TRC20_SHARED' AND status IN ('PENDING','PROCESSING','PAID')");
        DB::statement('CREATE UNIQUE INDEX topup_trc20_transfer_identity_unique ON wallet_topup_orders (network_code, matched_tx_hash, matched_transfer_index)
            WHERE matched_tx_hash IS NOT NULL');
        DB::statement("CREATE INDEX topup_trc20_allocation_history ON wallet_topup_orders (deposit_address, requested_amount, identification_increment, created_at)
            WHERE payment_rail = 'TRC20_SHARED'");
        DB::statement("CREATE INDEX topup_trc20_expiration_scan ON wallet_topup_orders (expires_at)
            WHERE payment_rail = 'TRC20_SHARED' AND status = 'PENDING'");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_topup_identity_mutation() RETURNS trigger AS $$
            BEGIN
                IF (
                    NEW.tenant_id <> OLD.tenant_id OR NEW.user_id <> OLD.user_id OR NEW.wallet_id <> OLD.wallet_id OR
                    NEW.request_id <> OLD.request_id OR NEW.request_hash <> OLD.request_hash OR NEW.asset_code <> OLD.asset_code OR
                    NEW.amount <> OLD.amount OR NEW.payment_provider <> OLD.payment_provider OR
                    NEW.payment_rail IS DISTINCT FROM OLD.payment_rail OR NEW.requested_amount IS DISTINCT FROM OLD.requested_amount OR
                    NEW.expected_amount IS DISTINCT FROM OLD.expected_amount OR NEW.identification_increment IS DISTINCT FROM OLD.identification_increment OR
                    NEW.network_code IS DISTINCT FROM OLD.network_code OR NEW.deposit_address IS DISTINCT FROM OLD.deposit_address OR
                    NEW.token_contract IS DISTINCT FROM OLD.token_contract OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                ) THEN RAISE EXCEPTION 'top-up financial identity is immutable' USING ERRCODE = '55000'; END IF;
                IF OLD.matched_tx_hash IS NOT NULL AND (
                    NEW.matched_tx_hash IS DISTINCT FROM OLD.matched_tx_hash OR
                    NEW.matched_transfer_index IS DISTINCT FROM OLD.matched_transfer_index OR
                    NEW.blockchain_detected_at IS DISTINCT FROM OLD.blockchain_detected_at
                ) THEN RAISE EXCEPTION 'top-up blockchain match is immutable' USING ERRCODE = '55000'; END IF;
                IF OLD.blockchain_confirmed_at IS NOT NULL AND NEW.blockchain_confirmed_at IS DISTINCT FROM OLD.blockchain_confirmed_at
                THEN RAISE EXCEPTION 'top-up blockchain confirmation is immutable' USING ERRCODE = '55000'; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS topup_trc20_expiration_scan');
        DB::statement('DROP INDEX IF EXISTS topup_trc20_allocation_history');
        DB::statement('DROP INDEX IF EXISTS topup_trc20_transfer_identity_unique');
        DB::statement('DROP INDEX IF EXISTS topup_trc20_active_amount_unique');
        DB::statement('ALTER TABLE wallet_topup_orders DROP CONSTRAINT IF EXISTS topup_trc20_expired_check');
        DB::statement('ALTER TABLE wallet_topup_orders DROP CONSTRAINT IF EXISTS topup_trc20_paid_check');
        DB::statement('ALTER TABLE wallet_topup_orders DROP CONSTRAINT IF EXISTS topup_trc20_detection_state_check');
        DB::statement('ALTER TABLE wallet_topup_orders DROP CONSTRAINT IF EXISTS topup_trc20_match_check');
        DB::statement('ALTER TABLE wallet_topup_orders DROP CONSTRAINT IF EXISTS topup_trc20_identity_check');
        Schema::table('wallet_topup_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_rail', 'requested_amount', 'expected_amount', 'identification_increment', 'network_code',
                'deposit_address', 'token_contract', 'matched_tx_hash', 'matched_transfer_index',
                'blockchain_detected_at', 'blockchain_confirmed_at',
            ]);
        });

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
        SQL);
    }
};
