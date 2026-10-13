<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('u1_test_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('reference_id')->unique();
            $table->foreign('reference_id')->references('id')->on('platform_card_provider_references')->restrictOnDelete();
            $table->string('customer_no', 100);
            $table->string('base_url', 200);
            $table->text('api_key_encrypted');
            $table->char('creation_hash', 64);
            $table->timestampsTz();
        });
        Schema::create('u1_test_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')->constrained('u1_test_connections')->restrictOnDelete();
            $table->uuid('actor_id');
            $table->unsignedInteger('connection_version');
            $table->string('notification_type', 40);
            $table->string('status', 20);
            $table->text('response_encrypted')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('finished_at')->nullable();
            $table->index(['connection_id', 'requested_at']);
        });
        Schema::create('u1_test_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')->constrained('u1_test_connections')->restrictOnDelete();
            $table->char('body_digest', 64);
            $table->string('notification_type', 40)->nullable();
            $table->boolean('verified');
            $table->string('verification_reason', 40);
            $table->text('body_encrypted');
            $table->unsignedInteger('delivery_count')->default(1);
            $table->timestampTz('received_at');
            $table->timestampTz('last_received_at');
            $table->unique(['connection_id', 'body_digest']);
            $table->index(['connection_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('u1_test_notifications');
        Schema::dropIfExists('u1_test_requests');
        Schema::dropIfExists('u1_test_connections');
    }
};
