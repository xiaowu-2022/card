<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_market_snapshots', fn (Blueprint $table) => $table->jsonb('usdt_rates')->nullable());
    }

    public function down(): void
    {
        Schema::table('asset_market_snapshots', fn (Blueprint $table) => $table->dropColumn('usdt_rates'));
    }
};
