<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fixed amounts are never reinterpreted as percentages. Explicit configuration required.
        DB::statement('ALTER TABLE tenant_business_settings ADD COLUMN withdrawal_fee_percent NUMERIC(10,8) NULL');
        DB::statement('ALTER TABLE tenant_business_settings ADD CONSTRAINT tron_withdrawal_percent_valid CHECK (withdrawal_fee_percent >= 0 AND withdrawal_fee_percent < 100)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenant_business_settings DROP COLUMN withdrawal_fee_percent');
    }
};
