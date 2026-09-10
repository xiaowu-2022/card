<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_provider_transactions', function (Blueprint $table): void {
            $table->timestampTz('initiation_attempted_at')->nullable()->after('amount');
            $table->timestampTz('initiation_lease_expires_at')->nullable()->after('initiation_attempted_at');
        });

        DB::statement('CREATE UNIQUE INDEX wallet_topup_ledger_entry_unique ON wallet_topup_orders (ledger_entry_id) WHERE ledger_entry_id IS NOT NULL');
        DB::statement("ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_financial_state_check CHECK (
            (status = 'CREDITED' AND paid_at IS NOT NULL AND credited_at IS NOT NULL AND ledger_entry_id IS NOT NULL)
            OR
            (status IN ('PAID', 'REFUNDED') AND paid_at IS NOT NULL AND credited_at IS NULL AND ledger_entry_id IS NULL)
            OR
            (status IN ('PENDING', 'PROCESSING', 'FAILED', 'CANCELLED', 'EXPIRED') AND credited_at IS NULL AND ledger_entry_id IS NULL)
        )");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_topup_financial_fact_transition() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'CREDITED' AND (
                    NEW.status <> 'CREDITED' OR
                    NEW.paid_at IS DISTINCT FROM OLD.paid_at OR
                    NEW.credited_at IS DISTINCT FROM OLD.credited_at OR
                    NEW.ledger_entry_id IS DISTINCT FROM OLD.ledger_entry_id
                ) THEN
                    RAISE EXCEPTION 'credited top-up is an immutable financial fact' USING ERRCODE = '55000';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER wallet_topup_financial_fact_immutable
            BEFORE UPDATE ON wallet_topup_orders
            FOR EACH ROW EXECUTE FUNCTION enforce_topup_financial_fact_transition();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS wallet_topup_financial_fact_immutable ON wallet_topup_orders');
        DB::statement('DROP FUNCTION IF EXISTS enforce_topup_financial_fact_transition()');
        DB::statement('ALTER TABLE wallet_topup_orders DROP CONSTRAINT IF EXISTS topup_financial_state_check');
        DB::statement('DROP INDEX IF EXISTS wallet_topup_ledger_entry_unique');
        Schema::table('payment_provider_transactions', function (Blueprint $table): void {
            $table->dropColumn(['initiation_attempted_at', 'initiation_lease_expires_at']);
        });
    }
};
