<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Retain failed applications unchanged while allowing a new linked attempt.
        DB::statement('DROP INDEX kyc_one_pending_application_per_user');
        DB::statement("CREATE UNIQUE INDEX kyc_one_pending_application_per_user ON kyc_applications (tenant_id, user_id) WHERE review_status = 'PENDING' AND processing_status IS DISTINCT FROM 'FAILED'");
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: failed KYC application history must be preserved.');
    }
};
