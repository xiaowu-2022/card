<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_sms_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->text('access_key_id')->nullable();
            $table->text('access_key_secret')->nullable();
            $table->string('sign_name', 100)->default('');
            $table->string('verification_template_code', 100)->default('');
            $table->string('existing_account_template_code', 100)->nullable();
            $table->unsignedInteger('resend_interval_seconds')->default(60);
            $table->unsignedInteger('code_ttl_seconds')->default(600);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE tenant_sms_settings ADD CONSTRAINT tenant_sms_timing_check CHECK (resend_interval_seconds BETWEEN 60 AND 3600 AND code_ttl_seconds BETWEEN 60 AND 3600 AND resend_interval_seconds <= code_ttl_seconds)');
        DB::statement("ALTER TABLE tenant_sms_settings ADD CONSTRAINT tenant_sms_enabled_check CHECK (NOT enabled OR (access_key_id IS NOT NULL AND access_key_secret IS NOT NULL AND sign_name <> '' AND verification_template_code <> ''))");
        Schema::table('registration_challenges', function (Blueprint $table): void {
            $table->boolean('sms_delivery_uncertain')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('registration_challenges', fn (Blueprint $table) => $table->dropColumn('sms_delivery_uncertain'));
        Schema::dropIfExists('tenant_sms_settings');
    }
};
