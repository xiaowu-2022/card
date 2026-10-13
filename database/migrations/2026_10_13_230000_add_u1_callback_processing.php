<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('u1_notifications', function (Blueprint $table): void {
            $table->string('api_id', 120)->nullable();
            $table->char('resource_hash', 64)->nullable();
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->timestampTz('processing_started_at')->nullable();
            $table->index(['connection_id', 'api_id']);
            $table->index(['connection_id', 'notification_type', 'resource_hash']);
        });
        Schema::create('u1_transaction_positions', function (Blueprint $table): void {
            $table->uuid('card_id')->primary();
            $table->foreign('card_id')->references('id')->on('user_cards')->restrictOnDelete();
            $table->unsignedInteger('next_offset')->default(0);
            $table->timestampTz('updated_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('U1 callback evidence requires a forward migration.');
    }
};
