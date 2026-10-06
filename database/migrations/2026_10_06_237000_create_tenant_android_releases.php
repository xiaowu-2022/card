<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_android_releases', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->primary()->constrained('tenants')->restrictOnDelete();
            $table->jsonb('metadata');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_android_releases');
    }
};
