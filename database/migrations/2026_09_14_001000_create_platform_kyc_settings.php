<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_kyc_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->boolean('enabled');
            $table->unsignedInteger('max_accounts_per_identity');
            $table->string('review_mode', 32);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE platform_kyc_settings ADD CONSTRAINT platform_kyc_singleton CHECK (id = '00000000-0000-4000-8000-000000000001'::uuid), ADD CONSTRAINT platform_kyc_limit CHECK (max_accounts_per_identity BETWEEN 1 AND 100), ADD CONSTRAINT platform_kyc_mode CHECK (review_mode IN ('MANUAL','AUTOMATIC'))");
        // Explicit global configuration is required after upgrade; do not silently
        // promote any company's automatic policy into a platform-wide policy.
        DB::table('platform_kyc_settings')->insert(['id' => '00000000-0000-4000-8000-000000000001', 'enabled' => false, 'max_accounts_per_identity' => 1, 'review_mode' => 'MANUAL', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_kyc_settings');
    }
};
