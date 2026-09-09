<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 24)->index();
            $table->string('default_locale', 16)->default('en');
            $table->string('timezone', 64)->default('UTC');
            $table->string('default_asset', 12)->default('USD');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('tenant_domains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('hostname')->unique();
            $table->string('domain_type', 24);
            $table->string('status', 32)->index();
            $table->string('verification_token', 128)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('verified_at')->nullable();
            $table->string('ssl_status', 24)->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('tenant_branding', function (Blueprint $table): void {
            $table->uuid('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->string('brand_name');
            $table->string('logo_object_key')->nullable();
            $table->string('logo_dark_object_key')->nullable();
            $table->string('favicon_object_key')->nullable();
            $table->string('primary_color', 7)->default('#155EEF');
            $table->string('support_email')->nullable();
            $table->string('support_url')->nullable();
            $table->string('copyright_text')->nullable();
            $table->timestampsTz();
        });

        Schema::create('tenant_locales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('locale', 16);
            $table->boolean('enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'locale']);
        });

        Schema::create('tenant_business_settings', function (Blueprint $table): void {
            $table->uuid('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->decimal('required_security_deposit_amount', 20, 8)->default('0.00000000');
            $table->string('required_security_deposit_asset', 12)->default('USD');
            $table->boolean('allow_wallet_topup')->default(false);
            $table->boolean('allow_withdrawal')->default(false);
            $table->timestampsTz();
        });

        Schema::create('tenant_kyc_settings', function (Blueprint $table): void {
            $table->uuid('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('max_accounts_per_identity')->nullable();
            $table->string('review_mode', 32)->default('MANUAL');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('DRAFT','ACTIVE','SUSPENDED','CLOSED'))");
        DB::statement("ALTER TABLE tenant_domains ADD CONSTRAINT tenant_domains_type_check CHECK (domain_type IN ('SYSTEM_SUBDOMAIN','CUSTOM_DOMAIN'))");
        DB::statement("ALTER TABLE tenant_domains ADD CONSTRAINT tenant_domains_status_check CHECK (status IN ('PENDING_VERIFICATION','VERIFIED','ACTIVE','FAILED','DISABLED'))");
        DB::statement("ALTER TABLE tenant_branding ADD CONSTRAINT tenant_branding_primary_color_check CHECK (primary_color ~ '^#[0-9A-Fa-f]{6}$')");
        DB::statement('ALTER TABLE tenant_business_settings ADD CONSTRAINT tenant_deposit_nonnegative_check CHECK (required_security_deposit_amount >= 0)');
        DB::statement("ALTER TABLE tenant_kyc_settings ADD CONSTRAINT tenant_kyc_review_mode_check CHECK (review_mode IN ('MANUAL','PROVIDER_AUTOMATIC'))");
        DB::statement('CREATE UNIQUE INDEX tenant_domains_one_primary_per_tenant ON tenant_domains (tenant_id) WHERE is_primary = true');
        DB::statement('CREATE UNIQUE INDEX tenant_locales_one_default_per_tenant ON tenant_locales (tenant_id) WHERE is_default = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_kyc_settings');
        Schema::dropIfExists('tenant_business_settings');
        Schema::dropIfExists('tenant_locales');
        Schema::dropIfExists('tenant_branding');
        Schema::dropIfExists('tenant_domains');
        Schema::dropIfExists('tenants');
    }
};
