<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('platform_card_provider_references', fn (Blueprint $table) => $table->jsonb('photonpay_report_snapshot')->nullable());
    }
    public function down(): void
    {
        Schema::table('platform_card_provider_references', fn (Blueprint $table) => $table->dropColumn('photonpay_report_snapshot'));
    }
};
