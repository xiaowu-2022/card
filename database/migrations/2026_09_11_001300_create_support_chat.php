<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE support_conversations (
                id uuid PRIMARY KEY,
                tenant_id uuid NOT NULL REFERENCES tenants(id),
                user_id uuid NOT NULL,
                last_sequence integer NOT NULL DEFAULT 0 CHECK (last_sequence >= 0),
                last_sender varchar(8) NOT NULL CHECK (last_sender IN ('USER', 'ADMIN')),
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                UNIQUE (tenant_id, user_id),
                UNIQUE (id, tenant_id),
                UNIQUE (id, tenant_id, user_id),
                FOREIGN KEY (user_id, tenant_id) REFERENCES users(id, tenant_id)
            );
            CREATE INDEX support_inbox ON support_conversations(tenant_id, updated_at DESC, id);
            CREATE TABLE support_messages (
                id uuid PRIMARY KEY,
                tenant_id uuid NOT NULL,
                conversation_id uuid NOT NULL,
                sequence integer NOT NULL CHECK (sequence > 0),
                sender_user_id uuid,
                sender_admin_id uuid REFERENCES admin_users(id),
                request_id uuid NOT NULL,
                support_message text NOT NULL,
                image_object_key text,
                image_hash varchar(64),
                image_mime varchar(20),
                CHECK ((image_object_key IS NULL AND image_hash IS NULL AND image_mime IS NULL)
                    OR (image_object_key IS NOT NULL AND image_hash IS NOT NULL AND image_mime IS NOT NULL AND image_mime IN ('image/jpeg', 'image/png', 'image/webp'))),
                created_at timestamptz NOT NULL,
                CHECK ((sender_user_id IS NOT NULL) <> (sender_admin_id IS NOT NULL)),
                UNIQUE (tenant_id, conversation_id, sequence),
                FOREIGN KEY (conversation_id, tenant_id) REFERENCES support_conversations(id, tenant_id),
                FOREIGN KEY (conversation_id, tenant_id, sender_user_id) REFERENCES support_conversations(id, tenant_id, user_id)
            );
            CREATE UNIQUE INDEX support_user_request ON support_messages(tenant_id, sender_user_id, request_id) WHERE sender_user_id IS NOT NULL;
            CREATE UNIQUE INDEX support_admin_request ON support_messages(tenant_id, sender_admin_id, request_id) WHERE sender_admin_id IS NOT NULL;
            SQL);
        $permissionId = DB::table('permissions')->where('name', 'support.manage')->value('id');
        if (! $permissionId) {
            $permissionId = (string) Str::uuid();
            DB::table('permissions')->insert(['id' => $permissionId, 'name' => 'support.manage', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('roles')->where('scope_type', 'TENANT')->whereIn('name', ['TENANT_OWNER', 'TENANT_ADMIN', 'SUPPORT'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE support_messages');
        DB::statement('DROP TABLE support_conversations');
        // Leave permission metadata intact: rollback must not change administrator assignments.
    }
};
