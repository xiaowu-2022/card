<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE withdrawal_orders DROP CONSTRAINT withdrawal_fee_valid');
        DB::statement('ALTER TABLE withdrawal_orders ADD CONSTRAINT withdrawal_fee_valid CHECK (fee_amount >= 0 AND fee_amount < amount AND fee_amount = trunc(fee_amount, 6))');
    }

    public function down(): void
    {
        throw new LogicException('Percentage fee snapshots require a forward-only migration.');
    }
};
