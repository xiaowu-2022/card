<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tenant_business_settings ADD COLUMN tron_minimum_deposit NUMERIC(20,8) NOT NULL DEFAULT 0 CHECK (tron_minimum_deposit >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenant_business_settings DROP COLUMN tron_minimum_deposit');
    }
};
