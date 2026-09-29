<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_transactions', function (Blueprint $table): void {
            $table->decimal('fee_amount', 20, 8)->nullable();
            $table->string('fee_currency', 3)->nullable();
            $table->decimal('fee_return_amount', 20, 8)->nullable();
            $table->string('fee_return_currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('card_transactions', function (Blueprint $table): void {
            $table->dropColumn(['fee_amount', 'fee_currency', 'fee_return_amount', 'fee_return_currency']);
        });
    }
};
