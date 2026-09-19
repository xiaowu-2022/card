<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_business_settings', fn (Blueprint $table) => $table->string('invitation_poster_background')->nullable());
    }

    public function down(): void
    {
        Schema::table('tenant_business_settings', fn (Blueprint $table) => $table->dropColumn('invitation_poster_background'));
    }
};
