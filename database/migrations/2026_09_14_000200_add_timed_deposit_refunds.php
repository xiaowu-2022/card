<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_deposit_refund_requests', function (Blueprint $table): void {
            $table->integer('refund_wait_days')->nullable();
            $table->timestampTz('refund_eligible_at')->nullable();
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->string('progress', 32)->nullable();
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE security_deposit_refund_requests ADD CONSTRAINT timed_refund_snapshot CHECK (
                (refund_wait_days IS NULL AND refund_eligible_at IS NULL AND cancel_requested_at IS NULL AND progress IS NULL)
                OR (refund_wait_days IS NOT NULL AND refund_wait_days BETWEEN 0 AND 3650 AND refund_eligible_at IS NOT NULL AND progress IS NOT NULL
                    AND refund_eligible_at=created_at+make_interval(secs=>refund_wait_days*86400)
                    AND progress IN ('freezing','waiting','blocked','restoring','completed','cancelled'))
            );
            CREATE OR REPLACE FUNCTION guard_timed_deposit_refund() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP='UPDATE' AND (NEW.refund_wait_days IS DISTINCT FROM OLD.refund_wait_days
                    OR NEW.refund_eligible_at IS DISTINCT FROM OLD.refund_eligible_at
                    OR (OLD.cancel_requested_at IS NOT NULL AND NEW.cancel_requested_at IS DISTINCT FROM OLD.cancel_requested_at)) THEN
                    RAISE EXCEPTION 'Refund deadline and cancellation intent are immutable';
                END IF;
                IF NEW.refund_wait_days IS NOT NULL AND NEW.status='COMPLETED' THEN
                    IF NEW.cancel_requested_at IS NOT NULL OR NEW.refund_eligible_at>clock_timestamp()
                        OR NEW.completed_at<NEW.refund_eligible_at OR NEW.progress<>'completed' THEN
                        RAISE EXCEPTION 'Timed refund is not eligible for settlement';
                    END IF;
                    IF EXISTS (SELECT 1 FROM user_cards c WHERE c.tenant_id=NEW.tenant_id AND c.user_id=NEW.user_id
                        AND (c.provider_status NOT IN ('frozen','cancelled') OR NOT (NEW.card_checks ? c.id::text)
                            OR (NEW.card_checks->>c.id::text)::bigint<>c.refresh_generation))
                        OR (SELECT count(*) FROM jsonb_object_keys(NEW.card_checks))<>(SELECT count(*) FROM user_cards WHERE tenant_id=NEW.tenant_id AND user_id=NEW.user_id)
                        OR EXISTS (SELECT 1 FROM card_issue_orders WHERE tenant_id=NEW.tenant_id AND user_id=NEW.user_id AND status IN ('PROCESSING','UNKNOWN'))
                        OR EXISTS (SELECT 1 FROM card_management_orders WHERE tenant_id=NEW.tenant_id AND user_id=NEW.user_id AND status IN ('QUOTING','QUOTED','PROCESSING','UNKNOWN')) THEN
                        RAISE EXCEPTION 'Timed refund requires confirmed frozen cards and resolved operations';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER timed_deposit_refund_guard BEFORE INSERT OR UPDATE ON security_deposit_refund_requests
                FOR EACH ROW EXECUTE FUNCTION guard_timed_deposit_refund();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Refund snapshots and safeguards require a forward migration.');
    }
};
