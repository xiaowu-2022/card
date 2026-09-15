<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tenant_domains ALTER COLUMN tenant_id DROP NOT NULL');
        DB::statement("ALTER TABLE tenant_domains ADD CONSTRAINT tenant_domains_assignment_check CHECK (tenant_id IS NOT NULL OR (domain_type = 'CUSTOM_DOMAIN' AND is_primary = false))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenant_domains ALTER COLUMN tenant_id SET NOT NULL');
        DB::statement('ALTER TABLE tenant_domains DROP CONSTRAINT tenant_domains_assignment_check');
    }
};
