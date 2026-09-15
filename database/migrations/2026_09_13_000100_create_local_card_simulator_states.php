<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Provider-simulator state, never a Wallet/Ledger balance source.
        Schema::create('local_card_simulator_states', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->text('payload');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_card_simulator_states');
    }
};
