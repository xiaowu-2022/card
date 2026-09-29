<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_image_uploads', function (Blueprint $table) {
            $table->string('upload_mode', 32)->default('verified_copy');
        });
        Schema::table('stored_images', function (Blueprint $table) {
            $table->unsignedBigInteger('size')->nullable()->change();
            $table->string('sha256', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('KYC URL upload records must be retained; restore application code without dropping their metadata.');
    }
};
