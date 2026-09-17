<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Development rule replacement: metadata only, never replay or rewrite money.
        DB::statement('LOCK TABLE paid_promotion_cycles, paid_promotion_rebates IN ACCESS EXCLUSIVE MODE');
        DB::statement('ALTER TABLE paid_promotion_cycles DISABLE TRIGGER promotion_cycle_policy');
        DB::statement('ALTER TABLE paid_promotion_cycles DISABLE TRIGGER protect_paid_promotion_cycles');
        DB::table('paid_promotion_cycles')->where('rebate_policy', '<>', 'AUTO_FIRST_FUNDING')->update(['rebate_policy' => 'AUTO_FIRST_FUNDING']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('ALTER TABLE paid_promotion_cycles ENABLE TRIGGER protect_paid_promotion_cycles');
        DB::statement('ALTER TABLE paid_promotion_cycles ENABLE TRIGGER promotion_cycle_policy');
        DB::statement("ALTER TABLE paid_promotion_cycles ALTER COLUMN rebate_policy SET DEFAULT 'AUTO_FIRST_FUNDING'");
        DB::statement('ALTER TABLE paid_promotion_cycles DROP CONSTRAINT promotion_rebate_policy');
        DB::statement("ALTER TABLE paid_promotion_cycles ADD CONSTRAINT promotion_rebate_policy CHECK (rebate_policy='AUTO_FIRST_FUNDING')");
        DB::statement("ALTER TABLE paid_promotion_rebates ALTER COLUMN source SET DEFAULT 'AUTO'");
        DB::statement('ALTER TABLE paid_promotion_rebates DROP CONSTRAINT paid_promotion_rebates_check');
        DB::statement(<<<'SQL'
            ALTER TABLE paid_promotion_rebates ADD CONSTRAINT paid_promotion_rebates_check CHECK (
              amount > 0 AND target > 0 AND direct_count >= 0 AND indirect_count >= 0 AND 2*direct_count+indirect_count >= 2*target
              AND source='AUTO' AND paid_total IS NOT NULL AND paid_total >= amount
              AND reviewer_id IS NULL AND reviewed_at IS NULL AND reason IS NULL
              AND status IN ('PENDING','APPROVED')
              AND ((status='APPROVED')=(ledger_entry_id IS NOT NULL))
              AND ((status='APPROVED')=(processed_at IS NOT NULL))
            )
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Automatic annual fee returns are the sole supported rule.');
    }
};
