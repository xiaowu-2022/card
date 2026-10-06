<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE TABLE support_agent_reads (
            tenant_id uuid NOT NULL, conversation_id uuid NOT NULL, user_id uuid NOT NULL,
            through_sequence integer NOT NULL CHECK (through_sequence >= 0),
            PRIMARY KEY (tenant_id, conversation_id, user_id),
            FOREIGN KEY (conversation_id, tenant_id) REFERENCES support_conversations(id, tenant_id),
            FOREIGN KEY (user_id, tenant_id) REFERENCES users(id, tenant_id)
        )');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE support_agent_reads');
    }
};
