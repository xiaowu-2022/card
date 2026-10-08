<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_products', function (Blueprint $table): void {
            $table->string('monthly_fee_text', 255)->nullable();
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('card_products', fn (Blueprint $table) => $table->dropColumn(['monthly_fee_text', 'notes']));
    }
};
