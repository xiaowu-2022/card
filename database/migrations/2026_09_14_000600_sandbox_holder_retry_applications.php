<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX sandbox_holder_product_once');
        DB::statement('CREATE UNIQUE INDEX sandbox_holder_product_request ON provider_cardholders(provider,provider_cardholder_id,card_product_id,request_id) WHERE sandbox_existing_holder');
    }

    public function down(): void
    {
        throw new LogicException('Preserve failed sandbox applications.');
    }
};
