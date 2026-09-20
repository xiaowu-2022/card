<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_products', fn (Blueprint $table) => $table->decimal('balance_limit', 20, 8)->nullable());
        DB::statement('ALTER TABLE card_products ADD CONSTRAINT product_balance_limit_valid CHECK (balance_limit IS NULL OR (balance_limit >= 0 AND balance_limit = trunc(balance_limit, 2)))');
    }

    public function down(): void
    {
        Schema::table('card_products', fn (Blueprint $table) => $table->dropColumn('balance_limit'));
    }
};
