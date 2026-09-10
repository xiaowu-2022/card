<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE wallet_topup_orders DROP CONSTRAINT topup_status_check');
        DB::statement("ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_status_check CHECK (status IN ('PENDING','PROCESSING','UNKNOWN','PAID','REQUIRES_REVIEW','CREDITED','FAILED','CANCELLED','EXPIRED','REFUNDED'))");
        DB::statement('ALTER TABLE wallet_topup_orders DROP CONSTRAINT topup_financial_state_check');
        DB::statement("ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_financial_state_check CHECK (
            (status = 'CREDITED' AND paid_at IS NOT NULL AND credited_at IS NOT NULL AND ledger_entry_id IS NOT NULL)
            OR (status IN ('PAID', 'REFUNDED') AND paid_at IS NOT NULL AND credited_at IS NULL AND ledger_entry_id IS NULL)
            OR (status IN ('PENDING', 'PROCESSING', 'UNKNOWN', 'REQUIRES_REVIEW', 'FAILED', 'CANCELLED', 'EXPIRED')
                AND credited_at IS NULL AND ledger_entry_id IS NULL)
        )");

        DB::statement('DROP INDEX topup_trc20_active_amount_unique');
        DB::statement("CREATE UNIQUE INDEX topup_trc20_active_amount_unique ON wallet_topup_orders (deposit_address, expected_amount)
            WHERE payment_rail = 'TRC20_SHARED' AND status IN ('PENDING','PROCESSING','UNKNOWN','PAID','REQUIRES_REVIEW')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX topup_trc20_active_amount_unique');
        DB::statement("CREATE UNIQUE INDEX topup_trc20_active_amount_unique ON wallet_topup_orders (deposit_address, expected_amount)
            WHERE payment_rail = 'TRC20_SHARED' AND status IN ('PENDING','PROCESSING','PAID')");

        DB::statement('ALTER TABLE wallet_topup_orders DROP CONSTRAINT topup_status_check');
        DB::statement("ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_status_check CHECK (status IN ('PENDING','PROCESSING','PAID','CREDITED','FAILED','CANCELLED','EXPIRED','REFUNDED'))");
        DB::statement('ALTER TABLE wallet_topup_orders DROP CONSTRAINT topup_financial_state_check');
        DB::statement("ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_financial_state_check CHECK (
            (status = 'CREDITED' AND paid_at IS NOT NULL AND credited_at IS NOT NULL AND ledger_entry_id IS NOT NULL)
            OR (status IN ('PAID', 'REFUNDED') AND paid_at IS NOT NULL AND credited_at IS NULL AND ledger_entry_id IS NULL)
            OR (status IN ('PENDING', 'PROCESSING', 'FAILED', 'CANCELLED', 'EXPIRED') AND credited_at IS NULL AND ledger_entry_id IS NULL)
        )");
    }
};
