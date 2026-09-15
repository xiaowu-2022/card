<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Existing orders keep their original zero-fee economics. No Ledger history changes.
        DB::statement('ALTER TABLE tenant_business_settings ADD COLUMN withdrawal_fixed_fee NUMERIC(20,8) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE tenant_business_settings ADD CONSTRAINT withdrawal_fixed_fee_valid CHECK (withdrawal_fixed_fee >= 0 AND withdrawal_fixed_fee = trunc(withdrawal_fixed_fee, 2))');
        DB::statement('ALTER TABLE withdrawal_orders ADD COLUMN fee_amount NUMERIC(20,8) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE withdrawal_orders ADD COLUMN receive_amount NUMERIC(20,8) GENERATED ALWAYS AS (amount - fee_amount) STORED');
        DB::statement('ALTER TABLE withdrawal_orders ADD CONSTRAINT withdrawal_fee_valid CHECK (fee_amount >= 0 AND fee_amount < amount AND fee_amount = trunc(fee_amount, 2))');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_withdrawal_fee_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.fee_amount IS DISTINCT FROM OLD.fee_amount THEN
                    RAISE EXCEPTION 'Withdrawal fee snapshot is immutable';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER withdrawal_fee_immutable BEFORE UPDATE ON withdrawal_orders
                FOR EACH ROW EXECUTE FUNCTION reject_withdrawal_fee_mutation();
            SQL);
    }

    public function down(): void
    {
        // Fee snapshots are financial history, not disposable configuration.
        throw new LogicException('Fixed withdrawal fees require a forward-only migration.');
    }
};
