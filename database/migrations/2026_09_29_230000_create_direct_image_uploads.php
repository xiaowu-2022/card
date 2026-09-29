<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_image_uploads', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->references('id')->on('tenants');
            $t->uuid('user_id')->references('id')->on('users');
            $t->uuid('staging_image_id')->references('id')->on('stored_images');
            $t->uuid('image_id')->references('id')->on('stored_images');
            $t->string('purpose', 16);
            $t->string('field', 32);
            $t->unsignedInteger('max_bytes');
            $t->timestampTz('expires_at');
            $t->timestampTz('verified_at')->nullable();
            $t->timestampTz('claimed_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_image_uploads');
    }
};
