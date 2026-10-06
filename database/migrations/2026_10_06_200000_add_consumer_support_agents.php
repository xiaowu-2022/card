<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE support_user_agents (
                tenant_id uuid NOT NULL REFERENCES tenants(id), user_id uuid NOT NULL,
                enabled boolean NOT NULL DEFAULT false, support_name varchar(30), revision integer NOT NULL CHECK(revision > 0),
                created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
                PRIMARY KEY(tenant_id,user_id), FOREIGN KEY(user_id,tenant_id) REFERENCES users(id,tenant_id)
            );
            ALTER TABLE support_messages ADD COLUMN sender_support_user_id uuid;
            ALTER TABLE support_messages ADD CONSTRAINT support_user_sender_owner FOREIGN KEY(sender_support_user_id,tenant_id) REFERENCES users(id,tenant_id);
            ALTER TABLE support_messages DROP CONSTRAINT support_message_sender;
            ALTER TABLE support_messages ADD CONSTRAINT support_message_sender CHECK (
                (is_bot AND num_nonnulls(sender_user_id,sender_admin_id,sender_support_user_id)=0 AND image_object_key IS NULL)
                OR (NOT is_bot AND num_nonnulls(sender_user_id,sender_admin_id,sender_support_user_id)=1 AND reply_to_id IS NULL AND faq_id IS NULL)
            );
            CREATE UNIQUE INDEX support_agent_user_request ON support_messages(tenant_id,sender_support_user_id,request_id) WHERE sender_support_user_id IS NOT NULL;
            ALTER TABLE support_conversations DROP CONSTRAINT support_conversations_last_sender_check;
            ALTER TABLE support_conversations ADD CONSTRAINT support_conversations_last_sender_check CHECK(last_sender IN ('USER','ADMIN','BOT','AGENT'));
            ALTER TABLE support_transitions ADD COLUMN actor_kind varchar(16);
            CREATE TABLE support_user_quick_replies (
                id uuid PRIMARY KEY, tenant_id uuid NOT NULL, user_id uuid NOT NULL,
                title varchar(100) NOT NULL, body text NOT NULL, revision integer NOT NULL CHECK(revision > 0),
                archived_at timestamptz, created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
                FOREIGN KEY(tenant_id,user_id) REFERENCES support_user_agents(tenant_id,user_id)
            );
            CREATE INDEX support_user_reply_scope ON support_user_quick_replies(tenant_id,user_id,archived_at);
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain support identities and message history.');
    }
};
