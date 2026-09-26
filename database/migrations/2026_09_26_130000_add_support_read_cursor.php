<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE support_conversations ADD COLUMN user_read_sequence integer NOT NULL DEFAULT 0');
        // There was no historical read evidence. Begin counting new replies at rollout.
        DB::statement('UPDATE support_conversations SET user_read_sequence = last_sequence');
        DB::statement('ALTER TABLE support_conversations ADD CONSTRAINT support_user_read_cursor CHECK (user_read_sequence >= 0 AND user_read_sequence <= last_sequence)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE support_conversations DROP COLUMN user_read_sequence');
    }
};
