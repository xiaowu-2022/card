<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->uuidMorphs('tokenable');
            $table->string('name', 80);
            $table->string('token', 64)->unique();
            $table->text('abilities');
            $table->unsignedInteger('session_version');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['tenant_id', 'tokenable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_device_tokens');
    }
};
