<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_company_rails', function (Blueprint $table): void {
            $table->decimal('withdrawal_fee_percent', 10, 8)->nullable();
        });
        Schema::table('asset_withdrawal_orders', function (Blueprint $table): void {
            $table->decimal('fee_percent', 10, 8)->nullable();
        });
        DB::statement('ALTER TABLE asset_company_rails ADD CONSTRAINT asset_rail_fee_percent_range CHECK (withdrawal_fee_percent IS NULL OR (withdrawal_fee_percent >= 0 AND withdrawal_fee_percent < 100))');
        DB::statement('ALTER TABLE asset_withdrawal_orders ADD CONSTRAINT asset_order_fee_percent_range CHECK (fee_percent IS NULL OR (fee_percent >= 0 AND fee_percent < 100))');
        // Existing fixed fees and orders retain their meaning. New rates require explicit configuration.
        // protect_asset_order already protects all economics, including the new snapshot column.
    }

    public function down(): void
    {
        Schema::table('asset_withdrawal_orders', fn (Blueprint $table) => $table->dropColumn('fee_percent'));
        Schema::table('asset_company_rails', fn (Blueprint $table) => $table->dropColumn('withdrawal_fee_percent'));
    }
};
