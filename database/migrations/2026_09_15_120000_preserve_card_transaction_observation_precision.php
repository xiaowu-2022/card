<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE card_transactions ALTER COLUMN created_at TYPE TIMESTAMP(6) WITH TIME ZONE, ALTER COLUMN updated_at TYPE TIMESTAMP(6) WITH TIME ZONE');
    }

    public function down(): void
    {
        // Keep the increased precision: rolling back must not round recorded history.
    }
};
