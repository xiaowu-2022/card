<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_overview_recharge_adjustments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->decimal('amount', 26, 8);
            $t->date('business_date');
            $t->text('reason');
            $t->uuid('actor_id')->references('id')->on('admin_users');
            $t->uuid('request_id')->unique();
            $t->char('request_hash', 64);
            $t->timestampTz('created_at');
            $t->index(['business_date', 'created_at']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE platform_overview_recharge_adjustments ADD CHECK(amount <> 0);
            CREATE OR REPLACE FUNCTION protect_overview_recharge_adjustment() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
                RAISE EXCEPTION 'Overview display adjustments are append-only';
            END $$;
            CREATE TRIGGER overview_recharge_adjustment_immutable BEFORE UPDATE OR DELETE ON platform_overview_recharge_adjustments
                FOR EACH ROW EXECUTE FUNCTION protect_overview_recharge_adjustment();
            SQL);
    }

    public function down(): void
    {
        if (DB::table('platform_overview_recharge_adjustments')->exists()) {
            throw new RuntimeException('Retained overview adjustment history prevents rollback.');
        }
        Schema::dropIfExists('platform_overview_recharge_adjustments');
        DB::statement('DROP FUNCTION IF EXISTS protect_overview_recharge_adjustment()');
    }
};
