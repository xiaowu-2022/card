<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['card_products' => ['card_product_money_check', ['minimum_initial_load', 'minimum_reload']],
            'card_issue_orders' => ['card_issue_money_check', ['minimum_initial_load']]] as $table => [$constraint, $fields]) {
            $row = DB::selectOne('SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid=?::regclass AND conname=?', [$table, $constraint]);
            if (! $row) {
                throw new RuntimeException('Card amount constraint is missing: '.$constraint);
            }
            $definition = $row->definition;
            foreach ($fields as $field) {
                $definition = preg_replace('/'.$field.'\s*>=\s*\(?20\)?(?:::numeric)?/', $field.' > 0', $definition, 1, $count);
                if ($count !== 1) {
                    throw new RuntimeException('Unexpected card minimum constraint: '.$constraint);
                }
            }
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} {$definition}");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Per-product minimums and order snapshots require a forward migration.');
    }
};
