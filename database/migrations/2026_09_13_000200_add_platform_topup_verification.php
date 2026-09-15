<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE TABLE trc20_scan_cursors (id varchar(64) PRIMARY KEY, started_at timestamptz NOT NULL, scanned_through timestamptz NOT NULL, CHECK (scanned_through >= started_at))');
        $id = DB::table('permissions')->where('name', 'wallet_topups.verify')->value('id');
        if (! $id) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert(['id' => $id, 'name' => 'wallet_topups.verify', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        // Never automatically discard a production scan checkpoint or granted permission.
    }
};
