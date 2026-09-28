<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', fn (Blueprint $t) => $t->string('support_name', 30)->nullable());
        Schema::table('support_messages', fn (Blueprint $t) => $t->string('support_name', 30)->nullable());
        foreach (['support.read', 'support.send', 'support.agents.manage'] as $name) {
            $id = DB::table('permissions')->where('name', $name)->value('id') ?? (string) Str::uuid();
            DB::table('permissions')->insertOrIgnore(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $role) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('support_messages', fn (Blueprint $t) => $t->dropColumn('support_name'));
        Schema::table('admin_users', fn (Blueprint $t) => $t->dropColumn('support_name'));
    }
};
