<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE card_products ADD COLUMN opening_fee numeric(20,8) NULL CHECK (opening_fee >= 0)');
        // Preserve an existing unanimous price. Conflicting/no company prices require SaaS configuration.
        DB::statement('UPDATE card_products p SET opening_fee = prices.fee FROM (SELECT card_product_id, min(opening_fee) AS fee FROM tenant_card_product_configs GROUP BY card_product_id HAVING count(DISTINCT opening_fee) = 1) prices WHERE prices.card_product_id = p.id');
    }

    public function down(): void
    {
        throw new LogicException('Preserve platform pricing and historical order snapshots.');
    }
};
