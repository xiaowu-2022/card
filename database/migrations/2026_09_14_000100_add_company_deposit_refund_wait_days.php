<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_business_settings', function (Blueprint $table): void {
            $table->integer('security_deposit_refund_wait_days')->nullable();
        });
        DB::statement('ALTER TABLE tenant_business_settings ADD CONSTRAINT deposit_refund_wait_days_range CHECK (security_deposit_refund_wait_days IS NULL OR security_deposit_refund_wait_days BETWEEN 0 AND 3650)');
    }

    public function down(): void
    {
        Schema::table('tenant_business_settings', function (Blueprint $table): void {
            $table->dropColumn('security_deposit_refund_wait_days');
        });
    }
};
