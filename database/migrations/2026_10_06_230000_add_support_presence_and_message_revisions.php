<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE consumer_presence (
                tenant_id uuid NOT NULL, user_id uuid NOT NULL, seen_at timestamptz NOT NULL,
                PRIMARY KEY (tenant_id,user_id),
                FOREIGN KEY(user_id,tenant_id) REFERENCES users(id,tenant_id)
            );
            CREATE TABLE support_message_revisions (
                id uuid PRIMARY KEY, tenant_id uuid NOT NULL, conversation_id uuid NOT NULL,
                message_id uuid NOT NULL, actor_user_id uuid NOT NULL, request_id uuid NOT NULL,
                revision integer NOT NULL CHECK(revision > 0),
                operation varchar(8) NOT NULL CHECK(operation IN ('EDIT','DELETE')),
                body text, created_at timestamptz NOT NULL,
                CHECK ((operation='EDIT' AND body IS NOT NULL) OR (operation='DELETE' AND body IS NULL)),
                UNIQUE(tenant_id,message_id,revision), UNIQUE(tenant_id,actor_user_id,request_id),
                FOREIGN KEY(message_id,tenant_id,conversation_id) REFERENCES support_messages(id,tenant_id,conversation_id),
                FOREIGN KEY(actor_user_id,tenant_id) REFERENCES users(id,tenant_id)
            );
            CREATE TABLE support_message_revision_reads (
                tenant_id uuid NOT NULL, message_id uuid NOT NULL, conversation_id uuid NOT NULL,
                revision integer NOT NULL CHECK(revision > 0), read_at timestamptz NOT NULL,
                PRIMARY KEY(tenant_id,message_id),
                FOREIGN KEY(message_id,tenant_id,conversation_id) REFERENCES support_messages(id,tenant_id,conversation_id)
            );
            CREATE OR REPLACE FUNCTION protect_support_message_revision() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Support message revisions are immutable'; END $$;
            CREATE TRIGGER support_message_revision_immutable BEFORE UPDATE OR DELETE ON support_message_revisions
                FOR EACH ROW EXECUTE FUNCTION protect_support_message_revision();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain support revision audit history.');
    }
};
