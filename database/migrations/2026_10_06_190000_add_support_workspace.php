<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE support_hours (
                tenant_id uuid PRIMARY KEY REFERENCES tenants(id), weekly jsonb NOT NULL,
                revision integer NOT NULL CHECK(revision > 0), updated_at timestamptz NOT NULL
            );
            CREATE TABLE support_agent_accounts (
                admin_id uuid PRIMARY KEY REFERENCES admin_users(id), revision integer NOT NULL DEFAULT 1,
                session_version integer NOT NULL DEFAULT 1, created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL
            );
            CREATE TABLE support_quick_replies (
                id uuid PRIMARY KEY, tenant_id uuid REFERENCES tenants(id), admin_id uuid REFERENCES admin_users(id),
                title varchar(100) NOT NULL, body text NOT NULL, revision integer NOT NULL DEFAULT 1,
                archived_at timestamptz, created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
                CHECK ((tenant_id IS NOT NULL) <> (admin_id IS NOT NULL))
            );
            CREATE INDEX support_quick_company ON support_quick_replies(tenant_id,archived_at);
            CREATE INDEX support_quick_personal ON support_quick_replies(admin_id,archived_at);
            SQL);
        foreach (['support.hours.manage', 'support.replies.manage'] as $name) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insertOrIgnore(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            $id = DB::table('permissions')->where('name', $name)->value('id');
            foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $role) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $id]);
            }
        }
        $role = (string) Str::uuid();
        DB::table('roles')->insertOrIgnore(['id' => $role, 'scope_type' => 'TENANT', 'name' => 'SUPPORT_AGENT', 'created_at' => now(), 'updated_at' => now()]);
        $role = DB::table('roles')->where('scope_type', 'TENANT')->where('name', 'SUPPORT_AGENT')->value('id');
        DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('name', 'support.manage')->value('id')]);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain support workspace accounts, assignments and audit history.');
    }
};
