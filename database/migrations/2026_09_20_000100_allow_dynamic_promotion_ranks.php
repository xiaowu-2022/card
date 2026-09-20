<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['paid_promotion_levels', 'paid_promotion_cycles', 'paid_promotion_events', 'paid_promotion_shares'] as $table) {
            $checks = DB::select("SELECT conname, pg_get_expr(conbin, conrelid) AS definition FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'c'", [$table]);
            foreach ($checks as $check) {
                $definition = preg_replace('/\b(rank|source_rank)\s*<=\s*8\b/', '$1 <= 2147483647', $check->definition, -1, $count);
                if ($count) {
                    $name = str_replace('"', '""', $check->conname);
                    DB::statement("ALTER TABLE {$table} DROP CONSTRAINT \"{$name}\"");
                    DB::statement("ALTER TABLE {$table} ADD CONSTRAINT \"{$name}\" CHECK ({$definition})");
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Dynamic promotion ranks may already have immutable financial history.');
    }
};
