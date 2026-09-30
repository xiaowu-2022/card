<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_image_uploads', function (Blueprint $table) {
            $table->text('kyc_ocr_evidence_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('direct_image_uploads', function (Blueprint $table) {
            $table->dropColumn('kyc_ocr_evidence_encrypted');
        });
    }
};
