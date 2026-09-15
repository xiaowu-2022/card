<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX card_product_provider_bin_unique');
        DB::statement("CREATE UNIQUE INDEX card_product_provider_bin_unique ON card_products(provider, COALESCE(card_provider_reference_id, '00000000-0000-0000-0000-000000000000'::uuid), provider_product_ref) WHERE provider_product_ref <> '' AND archived_at IS NULL");
    }

    public function down(): void
    {
        // Reused BINs can conflict with archived history; rollback must fail closed.
        DB::statement('DROP INDEX card_product_provider_bin_unique');
        DB::statement("CREATE UNIQUE INDEX card_product_provider_bin_unique ON card_products(provider, COALESCE(card_provider_reference_id, '00000000-0000-0000-0000-000000000000'::uuid), provider_product_ref) WHERE provider_product_ref <> ''");
    }
};
