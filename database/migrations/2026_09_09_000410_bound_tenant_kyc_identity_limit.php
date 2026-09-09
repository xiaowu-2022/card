<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenant_kyc_settings')->where('max_accounts_per_identity', '>', 100)->update(['max_accounts_per_identity' => 100]);
        DB::statement('ALTER TABLE tenant_kyc_settings DROP CONSTRAINT tenant_kyc_max_accounts_check');
        DB::statement('ALTER TABLE tenant_kyc_settings ADD CONSTRAINT tenant_kyc_max_accounts_check CHECK (max_accounts_per_identity BETWEEN 1 AND 100)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenant_kyc_settings DROP CONSTRAINT tenant_kyc_max_accounts_check');
        DB::statement('ALTER TABLE tenant_kyc_settings ADD CONSTRAINT tenant_kyc_max_accounts_check CHECK (max_accounts_per_identity >= 1)');
    }
};
