<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_transaction_sync_batches', function (Blueprint $table): void {
            // Null preserves the historical whole-company/all-company intent.
            $table->jsonb('card_ids')->nullable();
            $table->string('execution_mode', 16)->default('queue');
        });
        Schema::table('card_transaction_sync_items', function (Blueprint $table): void {
            $table->index(['card_id', 'tenant_id', 'status', 'updated_at'], 'card_sync_last_success_index');
        });
    }

    public function down(): void
    {
        Schema::table('card_transaction_sync_items', fn (Blueprint $table) => $table->dropIndex('card_sync_last_success_index'));
        Schema::table('card_transaction_sync_batches', fn (Blueprint $table) => $table->dropColumn(['card_ids', 'execution_mode']));
    }
};
