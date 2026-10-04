<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_transaction_sync_batches', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('actor_id')->constrained('admin_users')->restrictOnDelete();
            $t->uuid('request_id');
            $t->foreignUuid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $t->date('date_from');
            $t->date('date_to');
            $t->timestampsTz();
            $t->unique(['actor_id', 'request_id']);
        });
        Schema::create('card_transaction_sync_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('batch_id')->constrained('card_transaction_sync_batches')->restrictOnDelete();
            $t->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $t->foreignUuid('card_id')->constrained('user_cards')->restrictOnDelete();
            $t->string('binding_hash', 64);
            $t->string('account_key', 64);
            $t->string('status', 16);
            $t->unsignedInteger('next_page')->default(1);
            $t->unsignedInteger('pages_processed')->default(0);
            $t->unsignedBigInteger('records_written')->default(0);
            $t->unsignedInteger('failures')->default(0);
            $t->string('error_code', 64)->nullable();
            $t->timestampTz('next_attempt_at');
            $t->timestampsTz();
            $t->unique(['batch_id', 'card_id']);
            $t->index(['status', 'next_attempt_at']);
        });
        Schema::create('card_transaction_sync_accounts', function (Blueprint $t): void {
            $t->string('account_key', 64)->primary();
            $t->timestampTz('next_request_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_transaction_sync_items');
        Schema::dropIfExists('card_transaction_sync_accounts');
        Schema::dropIfExists('card_transaction_sync_batches');
    }
};
