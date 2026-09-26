<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE wallet_transfers DROP CONSTRAINT wallet_transfers_amount_check');
        DB::statement('ALTER TABLE wallet_transfers ALTER COLUMN amount TYPE numeric(38,18)');
        DB::statement("ALTER TABLE wallet_transfers ADD CONSTRAINT wallet_transfers_amount_check CHECK (amount > 0 AND amount < 1000000000000 AND amount = trunc(amount, CASE asset_code WHEN 'ETH' THEN 18 WHEN 'USDC' THEN 6 ELSE 8 END))");
    }

    public function down(): void
    {
        throw new RuntimeException('Transfer precision cannot be reduced over immutable financial history.');
    }
};
