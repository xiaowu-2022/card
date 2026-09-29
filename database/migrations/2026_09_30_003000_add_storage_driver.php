<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_storage_settings', function (Blueprint $table) {
            $table->string('storage_driver', 16)->nullable();
            $table->unsignedInteger('driver_revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('media_storage_settings', function (Blueprint $table) {
            $table->dropColumn(['storage_driver', 'driver_revision']);
        });
    }
};
