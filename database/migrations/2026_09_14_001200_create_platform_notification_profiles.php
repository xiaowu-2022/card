<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_sms_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
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
        DB::statement('ALTER TABLE platform_sms_profiles ADD CONSTRAINT platform_sms_timing CHECK (resend_interval_seconds BETWEEN 60 AND 3600 AND code_ttl_seconds BETWEEN 60 AND 3600 AND resend_interval_seconds <= code_ttl_seconds)');
        DB::statement("ALTER TABLE platform_sms_profiles ADD CONSTRAINT platform_sms_enabled CHECK (NOT enabled OR (access_key_id IS NOT NULL AND access_key_secret IS NOT NULL AND sign_name <> '' AND verification_template_code <> ''))");
        Schema::create('platform_email_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->boolean('enabled')->default(false);
            $table->string('from_address', 254)->default('');
            $table->string('from_name', 100)->default('');
            $table->text('smtp_token')->nullable();
            $table->unsignedInteger('daily_recipient_limit')->default(10);
            $table->uuid('configuration_version');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE platform_email_profiles ADD CONSTRAINT platform_email_limit CHECK (daily_recipient_limit BETWEEN 0 AND 1000)');
        DB::statement("ALTER TABLE platform_email_profiles ADD CONSTRAINT platform_email_enabled CHECK (NOT enabled OR (smtp_token IS NOT NULL AND from_address <> '' AND from_name <> ''))");
        Schema::create('tenant_notification_profiles', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->primary()->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('sms_profile_id')->nullable()->constrained('platform_sms_profiles')->restrictOnDelete();
            $table->foreignUuid('email_profile_id')->nullable()->constrained('platform_email_profiles')->restrictOnDelete();
            $table->timestampsTz();
        });
        foreach (['sms', 'email'] as $channel) {
            DB::table("tenant_{$channel}_settings")->orderBy('id')->chunk(100, function ($rows) use ($channel): void {
                foreach ($rows as $row) {
                    $fields = (array) $row;
                    $tenantId = $fields['tenant_id'];
                    unset($fields['tenant_id']);
                    // Copy encrypted bytes verbatim, keeping email test configuration versions.
                    $fields['name'] = mb_substr(DB::table('tenants')->where('id', $tenantId)->value('name'), 0, 80).' · '.strtoupper($channel);
                    DB::table("platform_{$channel}_profiles")->insert($fields);
                    DB::table('tenant_notification_profiles')->upsert([
                        ['tenant_id' => $tenantId, "{$channel}_profile_id" => $row->id, 'created_at' => now(), 'updated_at' => now()],
                    ], ['tenant_id'], ["{$channel}_profile_id", 'updated_at']);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_notification_profiles');
        Schema::dropIfExists('platform_email_profiles');
        Schema::dropIfExists('platform_sms_profiles');
    }
};
