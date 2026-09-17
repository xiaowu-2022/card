<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paid_promotion_events', fn (Blueprint $t) => $t->boolean('is_first_funding')->nullable());
        Schema::table('paid_promotion_cycles', fn (Blueprint $t) => $t->string('rebate_policy', 32)->default('MANUAL_ALL_FUNDING'));
        Schema::table('paid_promotion_rebates', function (Blueprint $t): void {
            $t->string('source', 16)->default('MANUAL');
            $t->decimal('paid_total', 20, 8)->nullable();
            $t->timestampTz('processed_at')->nullable();
        });
        DB::statement("ALTER TABLE paid_promotion_cycles ADD CONSTRAINT promotion_rebate_policy CHECK (rebate_policy IN ('MANUAL_ALL_FUNDING','AUTO_FIRST_FUNDING'))");
        DB::statement('ALTER TABLE paid_promotion_rebates DROP CONSTRAINT paid_promotion_rebates_check');
        DB::statement(<<<'SQL'
            ALTER TABLE paid_promotion_rebates ADD CONSTRAINT paid_promotion_rebates_check CHECK (
              amount > 0 AND target > 0 AND direct_count >= 0 AND indirect_count >= 0 AND 2*direct_count+indirect_count >= 2*target
              AND ((status='APPROVED')=(ledger_entry_id IS NOT NULL)) AND (
                (source='MANUAL' AND paid_total IS NULL AND processed_at IS NULL AND status IN ('PENDING','APPROVED','REJECTED','WITHDRAWN')
                 AND ((status IN ('APPROVED','REJECTED'))=(reviewer_id IS NOT NULL AND reviewed_at IS NOT NULL)))
                OR (source='AUTO' AND paid_total IS NOT NULL AND paid_total >= amount AND reviewer_id IS NULL AND reviewed_at IS NULL
                 AND status IN ('PENDING','APPROVED') AND ((status='APPROVED')=(processed_at IS NOT NULL)))
              ))
            SQL);
        DB::statement("CREATE UNIQUE INDEX promotion_auto_rebate_paid_total ON paid_promotion_rebates(cycle_id,paid_total) WHERE source='AUTO'");
        $definition = DB::selectOne("SELECT pg_get_functiondef('protect_paid_promotion()'::regprocedure) AS definition")->definition;
        DB::unprepared(str_replace("'reviewed_at','reason'", "'reviewed_at','reason','processed_at'", $definition));
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_promotion_rebate_policy() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE policy text;
            BEGIN
              IF TG_TABLE_NAME='paid_promotion_cycles' THEN
                IF NEW.rebate_policy IS DISTINCT FROM OLD.rebate_policy THEN RAISE EXCEPTION 'Immutable rebate policy'; END IF;
              ELSE
                SELECT rebate_policy INTO policy FROM paid_promotion_cycles WHERE id=NEW.cycle_id AND tenant_id=NEW.tenant_id AND user_id=NEW.user_id;
                IF policy IS NULL OR (NEW.source='AUTO') IS DISTINCT FROM (policy='AUTO_FIRST_FUNDING') THEN RAISE EXCEPTION 'Rebate source must match period policy'; END IF;
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER promotion_cycle_policy BEFORE UPDATE ON paid_promotion_cycles FOR EACH ROW EXECUTE FUNCTION protect_promotion_rebate_policy();
            CREATE TRIGGER promotion_claim_policy BEFORE INSERT OR UPDATE ON paid_promotion_rebates FOR EACH ROW EXECUTE FUNCTION protect_promotion_rebate_policy();
            SQL);
    }

    public function down(): void
    {
        if (DB::table('paid_promotion_cycles')->where('rebate_policy', 'AUTO_FIRST_FUNDING')->exists()) {
            throw new RuntimeException('Automatic rebate periods exist; preserve their financial policy.');
        }
        DB::unprepared('DROP TRIGGER promotion_claim_policy ON paid_promotion_rebates; DROP TRIGGER promotion_cycle_policy ON paid_promotion_cycles; DROP FUNCTION protect_promotion_rebate_policy()');
        $definition = DB::selectOne("SELECT pg_get_functiondef('protect_paid_promotion()'::regprocedure) AS definition")->definition;
        DB::unprepared(str_replace("'reviewed_at','reason','processed_at'", "'reviewed_at','reason'", $definition));
        DB::statement('DROP INDEX promotion_auto_rebate_paid_total');
        DB::statement('ALTER TABLE paid_promotion_rebates DROP CONSTRAINT paid_promotion_rebates_check');
        Schema::table('paid_promotion_rebates', fn (Blueprint $t) => $t->dropColumn(['source', 'paid_total', 'processed_at']));
        Schema::table('paid_promotion_cycles', fn (Blueprint $t) => $t->dropColumn('rebate_policy'));
        Schema::table('paid_promotion_events', fn (Blueprint $t) => $t->dropColumn('is_first_funding'));
        DB::statement("ALTER TABLE paid_promotion_rebates ADD CONSTRAINT paid_promotion_rebates_check CHECK (amount > 0 AND target > 0 AND direct_count >= 0 AND indirect_count >= 0 AND 2*direct_count+indirect_count >= 2*target AND status IN ('PENDING','APPROVED','REJECTED','WITHDRAWN') AND ((status='APPROVED')=(ledger_entry_id IS NOT NULL)) AND ((status IN ('APPROVED','REJECTED'))=(reviewer_id IS NOT NULL AND reviewed_at IS NOT NULL)))");
    }
};
