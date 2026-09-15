<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_email_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('from_address', 254)->default('');
            $table->string('from_name', 100)->default('');
            $table->text('smtp_token')->nullable();
            $table->unsignedInteger('daily_recipient_limit')->default(10);
            $table->uuid('configuration_version');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE tenant_email_settings ADD CONSTRAINT tenant_email_limit_check CHECK (daily_recipient_limit BETWEEN 0 AND 1000)');
        DB::statement("ALTER TABLE tenant_email_settings ADD CONSTRAINT tenant_email_enabled_check CHECK (NOT enabled OR (smtp_token IS NOT NULL AND from_address <> '' AND from_name <> ''))");
        Schema::table('registration_challenges', fn (Blueprint $table) => $table->boolean('email_delivery_uncertain')->default(false));
        Schema::create('tenant_email_test_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('request_id');
            $table->uuid('configuration_version');
            $table->string('recipient_hash', 64);
            $table->string('status', 16);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'request_id']);
            $table->index(['tenant_id', 'configuration_version', 'recipient_hash']);
        });
        DB::statement("ALTER TABLE tenant_email_test_requests ADD CONSTRAINT tenant_email_test_status_check CHECK (status IN ('PENDING', 'ACCEPTED', 'REJECTED', 'UNKNOWN'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_email_test_requests');
        Schema::table('registration_challenges', fn (Blueprint $table) => $table->dropColumn('email_delivery_uncertain'));
        Schema::dropIfExists('tenant_email_settings');
    }
};
