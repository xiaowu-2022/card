<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_contact_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('request_id');
            $table->string('channel', 16);
            $table->text('destination');
            $table->string('destination_hash', 64);
            $table->string('session_hash', 64);
            $table->string('credential_hash', 64);
            $table->string('code_hash', 64);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->boolean('delivery_uncertain')->default(true);
            $table->timestampTz('expires_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'user_id', 'request_id']);
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['tenant_id', 'destination_hash', 'created_at']);
        });
        DB::statement("ALTER TABLE user_contact_changes ADD CONSTRAINT contact_change_channel CHECK (channel IN ('EMAIL', 'PHONE'))");
        DB::statement('ALTER TABLE user_contact_changes ADD CONSTRAINT contact_change_terminal CHECK (consumed_at IS NULL OR cancelled_at IS NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_contact_changes');
    }
};
