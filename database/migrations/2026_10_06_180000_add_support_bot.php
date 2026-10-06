<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE support_bot_settings (
                tenant_id uuid PRIMARY KEY REFERENCES tenants(id), enabled boolean NOT NULL DEFAULT false,
                revision integer NOT NULL DEFAULT 1 CHECK (revision > 0), updated_at timestamptz NOT NULL
            );
            CREATE TABLE support_faqs (
                id uuid PRIMARY KEY, tenant_id uuid REFERENCES tenants(id), overrides_id uuid REFERENCES support_faqs(id),
                question varchar(200) NOT NULL, variants jsonb NOT NULL DEFAULT '[]', keywords jsonb NOT NULL DEFAULT '[]',
                answer text NOT NULL, enabled boolean NOT NULL DEFAULT true, revision integer NOT NULL DEFAULT 1 CHECK (revision > 0),
                archived_at timestamptz, created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
                CHECK (overrides_id IS NULL OR tenant_id IS NOT NULL), CHECK (id IS DISTINCT FROM overrides_id),
                UNIQUE (tenant_id, overrides_id)
            );
            CREATE INDEX support_faq_scope ON support_faqs(tenant_id, archived_at);
            ALTER TABLE support_conversations ADD COLUMN mode varchar(8) NOT NULL DEFAULT 'HUMAN' CHECK (mode IN ('BOT','WAITING','HUMAN'));
            ALTER TABLE support_conversations ADD COLUMN revision integer NOT NULL DEFAULT 1 CHECK (revision > 0);
            ALTER TABLE support_conversations DROP CONSTRAINT support_conversations_last_sender_check;
            ALTER TABLE support_conversations ADD CONSTRAINT support_conversations_last_sender_check CHECK (last_sender IN ('USER','ADMIN','BOT'));
            ALTER TABLE support_messages ADD COLUMN is_bot boolean NOT NULL DEFAULT false;
            ALTER TABLE support_messages ADD COLUMN reply_to_id uuid;
            ALTER TABLE support_messages ADD COLUMN faq_id uuid REFERENCES support_faqs(id);
            ALTER TABLE support_messages ADD COLUMN faq_revision integer;
            ALTER TABLE support_messages ADD CONSTRAINT support_message_identity UNIQUE (id,tenant_id,conversation_id);
            ALTER TABLE support_messages ADD CONSTRAINT support_bot_reply_owner FOREIGN KEY (reply_to_id,tenant_id,conversation_id) REFERENCES support_messages(id,tenant_id,conversation_id);
            DO $$ DECLARE c record; BEGIN
                FOR c IN SELECT conname FROM pg_constraint WHERE conrelid='support_messages'::regclass AND contype='c'
                    AND pg_get_constraintdef(oid) LIKE '%sender_user_id%' AND pg_get_constraintdef(oid) LIKE '%sender_admin_id%'
                LOOP EXECUTE format('ALTER TABLE support_messages DROP CONSTRAINT %I',c.conname); END LOOP;
            END $$;
            ALTER TABLE support_messages ADD CONSTRAINT support_message_sender CHECK (
                (is_bot AND sender_user_id IS NULL AND sender_admin_id IS NULL AND image_object_key IS NULL)
                OR (NOT is_bot AND ((sender_user_id IS NOT NULL) <> (sender_admin_id IS NOT NULL)) AND reply_to_id IS NULL AND faq_id IS NULL)
            );
            CREATE UNIQUE INDEX support_bot_one_reply ON support_messages(reply_to_id) WHERE reply_to_id IS NOT NULL;
            CREATE TABLE support_transitions (
                id uuid PRIMARY KEY, tenant_id uuid NOT NULL, user_id uuid NOT NULL, conversation_id uuid NOT NULL,
                request_id uuid NOT NULL, intent_hash varchar(64) NOT NULL, action varchar(24) NOT NULL,
                actor_id uuid NOT NULL, from_mode varchar(8) NOT NULL, to_mode varchar(8) NOT NULL,
                created_at timestamptz NOT NULL,
                UNIQUE (tenant_id,user_id,request_id),
                FOREIGN KEY (conversation_id,tenant_id,user_id) REFERENCES support_conversations(id,tenant_id,user_id)
            );
            SQL);
        $name = 'support.bot.manage';
        $id = DB::table('permissions')->where('name', $name)->value('id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $role) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retain support bot history and schema when rolling back application code.');
    }
};
