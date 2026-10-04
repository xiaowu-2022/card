<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_adjustment_classifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('adjustment_id')->unique()->references('id')->on('manual_commission_adjustments');
            $t->uuid('tenant_id')->references('id')->on('tenants');
            $t->uuid('user_id')->references('id')->on('users');
            $t->string('kind', 16);
            $t->decimal('kind_before', 38, 18);
            $t->decimal('kind_after', 38, 18);
            $t->uuid('actor_id')->references('id')->on('admin_users');
            $t->string('actor_name');
            $t->string('reason', 500);
            $t->uuid('request_id');
            $t->timestampTz('created_at', 6);
            $t->unique(['tenant_id', 'user_id', 'request_id']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE commission_adjustment_classifications ADD CHECK (kind IN ('activation','annual','legacy') AND kind_before>=0 AND kind_after>=0 AND length(trim(reason))>0);
            CREATE TRIGGER commission_classification_immutable BEFORE UPDATE OR DELETE ON commission_adjustment_classifications FOR EACH ROW EXECUTE FUNCTION protect_manual_commission_adjustment();
            CREATE FUNCTION verify_commission_classification() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE a manual_commission_adjustments%ROWTYPE;
            BEGIN
                SELECT * INTO a FROM manual_commission_adjustments WHERE id=NEW.adjustment_id;
                IF a.id IS NULL OR a.tenant_id<>NEW.tenant_id OR a.user_id<>NEW.user_id
                    OR NEW.kind_after-NEW.kind_before <> CASE WHEN a.direction='INCREASE' THEN a.amount ELSE -a.amount END
                    THEN RAISE EXCEPTION 'Commission classification scope or amount mismatch'; END IF;
                RETURN NEW;
            END $$;
            CREATE CONSTRAINT TRIGGER commission_classification_evidence AFTER INSERT ON commission_adjustment_classifications DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_commission_classification();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Commission audit evidence must be retained.');
    }
};
