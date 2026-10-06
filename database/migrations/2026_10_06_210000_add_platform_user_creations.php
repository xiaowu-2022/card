<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE platform_user_creations (
                tenant_id uuid NOT NULL REFERENCES tenants(id),
                user_id uuid NOT NULL,
                actor_id uuid NOT NULL REFERENCES admin_users(id),
                request_id uuid NOT NULL,
                verified_at timestamptz NOT NULL,
                PRIMARY KEY (tenant_id, user_id),
                UNIQUE (tenant_id, actor_id, request_id),
                FOREIGN KEY (user_id, tenant_id) REFERENCES users(id, tenant_id)
            );
            CREATE OR REPLACE FUNCTION protect_platform_user_creation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Platform account creation evidence is immutable';
            END $$;
            CREATE TRIGGER platform_user_creation_immutable BEFORE UPDATE OR DELETE ON platform_user_creations
                FOR EACH ROW EXECUTE FUNCTION protect_platform_user_creation();
            SQL);
        $id = DB::table('permissions')->where('name', 'users.create')->value('id');
        if (! $id) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert(['id' => $id, 'name' => 'users.create', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $role) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retain platform account creation and verification evidence.');
    }
};
