<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 32);
            $table->string('provider_product_ref', 64);
            $table->string('name', 120);
            $table->string('card_currency', 12);
            $table->string('card_type', 24);
            $table->decimal('minimum_initial_load', 20, 8);
            $table->decimal('minimum_reload', 20, 8);
            $table->string('status', 16);
            $table->timestampsTz();

            $table->unique(['provider', 'provider_product_ref']);
            $table->index(['status', 'name']);
        });

        Schema::create('tenant_card_product_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('card_product_id');
            $table->string('display_name', 120)->nullable();
            $table->decimal('opening_fee', 20, 8);
            $table->unsignedSmallInteger('max_cards_per_user');
            $table->string('status', 16);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'card_product_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('card_product_id')->references('id')->on('card_products')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'sort_order']);
        });

        DB::statement("ALTER TABLE card_products ADD CONSTRAINT card_product_identity_check CHECK (
            provider = 'PHOTONPAY' AND card_currency = 'USD' AND card_type = 'REGULAR'
            AND provider_product_ref ~ '^[A-Za-z0-9._-]{4,64}$'
        )");
        DB::statement('ALTER TABLE card_products ADD CONSTRAINT card_product_money_check CHECK (
            minimum_initial_load >= 20 AND minimum_reload >= 20
        )');
        DB::statement("ALTER TABLE card_products ADD CONSTRAINT card_product_status_check CHECK (status IN ('DRAFT','ACTIVE','INACTIVE'))");
        DB::statement('ALTER TABLE tenant_card_product_configs ADD CONSTRAINT tenant_card_product_opening_fee_check CHECK (opening_fee >= 0)');
        DB::statement('ALTER TABLE tenant_card_product_configs ADD CONSTRAINT tenant_card_product_max_cards_check CHECK (max_cards_per_user BETWEEN 1 AND 100)');
        DB::statement("ALTER TABLE tenant_card_product_configs ADD CONSTRAINT tenant_card_product_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_card_product_configs');
        Schema::dropIfExists('card_products');
    }
};
