<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table): void {
            $table->string('apk_name', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table): void {
            $table->dropColumn('apk_name');
        });
    }
};
