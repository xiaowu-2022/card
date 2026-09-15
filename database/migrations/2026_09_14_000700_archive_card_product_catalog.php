<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_products', function (Blueprint $table): void {
            $table->timestampTz('archived_at')->nullable()->index();
        });
        DB::statement("ALTER TABLE card_products ADD CONSTRAINT archived_card_product_inactive CHECK (archived_at IS NULL OR status = 'INACTIVE')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE card_products DROP CONSTRAINT archived_card_product_inactive');
        Schema::table('card_products', fn (Blueprint $table) => $table->dropColumn('archived_at'));
    }
};
