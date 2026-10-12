<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE TABLE support_admin_reads (
            tenant_id uuid NOT NULL, conversation_id uuid NOT NULL,
            admin_id uuid NOT NULL REFERENCES admin_users(id),
            through_sequence integer NOT NULL CHECK (through_sequence >= 0),
            PRIMARY KEY (tenant_id, conversation_id, admin_id),
            FOREIGN KEY (conversation_id, tenant_id) REFERENCES support_conversations(id, tenant_id)
        )');
        DB::statement('CREATE TRIGGER zz_cleanup_recreation BEFORE INSERT ON support_admin_reads FOR EACH ROW EXECUTE FUNCTION guard_cleanup_recreation()');
        DB::statement('ALTER TABLE data_cleanup_items DROP CONSTRAINT data_cleanup_items_table_name_check');
        DB::statement("ALTER TABLE data_cleanup_items ADD CONSTRAINT data_cleanup_items_table_name_check CHECK (table_name IN ('".implode("','", \App\Application\Cleanup\CleanupSchema::TABLES)."'))");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE support_admin_reads');
    }
};
