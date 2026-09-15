<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX platform_topup_verification_request_unique ON audit_logs (actor_id, request_id) WHERE action = 'PLATFORM_TOPUP_VERIFICATION_REQUESTED'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX platform_topup_verification_request_unique');
    }
};
