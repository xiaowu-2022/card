<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_password_resets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('user_id')->nullable();
            $table->uuid('request_id');
            $table->string('channel', 16);
            $table->text('destination');
            $table->string('destination_hash', 64);
            $table->string('session_hash', 64);
            $table->string('ip_hash', 64);
            $table->string('credential_hash', 64)->nullable();
            $table->string('code_hash', 64);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->boolean('delivery_uncertain')->default(true);
            $table->timestampTz('expires_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'request_id']);
            $table->index(['tenant_id', 'destination_hash', 'created_at']);
            $table->index(['tenant_id', 'ip_hash', 'created_at']);
            $table->index(['tenant_id', 'session_hash', 'created_at']);
        });
        DB::statement("ALTER TABLE user_password_resets ADD CONSTRAINT password_reset_channel CHECK (channel IN ('EMAIL','PHONE'))");
        DB::statement('ALTER TABLE user_password_resets ADD CONSTRAINT password_reset_terminal CHECK (consumed_at IS NULL OR (cancelled_at IS NULL AND user_id IS NOT NULL))');
        DB::statement('ALTER TABLE user_password_resets ADD CONSTRAINT password_reset_mapping CHECK ((user_id IS NULL) = (credential_hash IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_password_resets');
    }
};
