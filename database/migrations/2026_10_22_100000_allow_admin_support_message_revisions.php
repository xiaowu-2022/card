<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE support_message_revisions ALTER COLUMN actor_user_id DROP NOT NULL;
            ALTER TABLE support_message_revisions ADD COLUMN actor_admin_id uuid REFERENCES admin_users(id);
            ALTER TABLE support_message_revisions ADD CONSTRAINT support_revision_one_actor
                CHECK ((actor_user_id IS NOT NULL)::integer + (actor_admin_id IS NOT NULL)::integer = 1);
            CREATE UNIQUE INDEX support_revision_admin_request
                ON support_message_revisions(tenant_id, actor_admin_id, request_id);
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain support revision audit history.');
    }
};
