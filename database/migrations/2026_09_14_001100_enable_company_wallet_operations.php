<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only configuration changes: no orders, balances or financial history.
        DB::statement('ALTER TABLE tenant_business_settings ALTER COLUMN allow_wallet_topup SET DEFAULT true, ALTER COLUMN allow_withdrawal SET DEFAULT true');
        DB::table('tenant_business_settings')->where(function ($query): void {
            $query->where('allow_wallet_topup', false)->orWhere('allow_withdrawal', false);
        })->update(['allow_wallet_topup' => true, 'allow_withdrawal' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenant_business_settings ALTER COLUMN allow_wallet_topup SET DEFAULT false, ALTER COLUMN allow_withdrawal SET DEFAULT false');
        // Do not invent prior values or disable already-enabled company operations.
    }
};
