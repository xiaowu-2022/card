<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE wealth_orders ADD COLUMN maturity_policy varchar(32),
    ADD COLUMN redeem_before timestamptz,
    ADD COLUMN previous_order_id uuid UNIQUE REFERENCES wealth_orders(id),
    ADD COLUMN close_reason varchar(16),
    ADD COLUMN redeem_request_id uuid;
ALTER TABLE wealth_orders ALTER COLUMN maturity_policy SET DEFAULT 'MANUAL_REDEEM_RENEW';
ALTER TABLE wealth_orders ADD CONSTRAINT wealth_redeem_request_unique UNIQUE (tenant_id,user_id,redeem_request_id);
ALTER TABLE wealth_orders ADD CONSTRAINT wealth_maturity_policy_check CHECK ((
    (maturity_policy IS NULL AND redeem_before IS NULL AND previous_order_id IS NULL AND close_reason IS NULL AND redeem_request_id IS NULL)
    OR (maturity_policy = 'MANUAL_REDEEM_RENEW' AND redeem_before IS NOT NULL
        AND redeem_before = ((date_trunc('day', matures_at AT TIME ZONE timezone) + interval '1 day') AT TIME ZONE timezone)
        AND ((status IN ('ACTIVE','CANCELLED') AND close_reason IS NULL AND redeem_request_id IS NULL)
            OR (status = 'MATURED' AND close_reason IS NOT NULL AND (
                (close_reason = 'REDEEMED' AND redeem_request_id IS NOT NULL AND closed_at < redeem_before)
                OR (close_reason = 'RENEWED' AND redeem_request_id IS NULL AND closed_at >= redeem_before)))))
) IS TRUE);
CREATE INDEX wealth_renewal_due ON wealth_orders(redeem_before) WHERE status='ACTIVE' AND maturity_policy IS NOT NULL;
SQL);
        // Extend only the allowed terminal fields; contract and terminal immutability remain enforced.
        $definition = DB::selectOne("SELECT pg_get_functiondef('protect_wealth_records()'::regprocedure) AS definition")->definition;
        $definition = str_replace("'status','close_entry_id'", "'close_reason','redeem_request_id','status','close_entry_id'", $definition);
        DB::unprepared($definition);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_wealth_renewal() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o wealth_orders%ROWTYPE; p wealth_orders%ROWTYPE; n wealth_orders%ROWTYPE;
BEGIN
 SELECT * INTO o FROM wealth_orders WHERE id=NEW.id;
 IF o.previous_order_id IS NOT NULL THEN
  SELECT * INTO p FROM wealth_orders WHERE id=o.previous_order_id;
  IF p.id IS NULL OR p.status <> 'MATURED' OR p.close_reason IS DISTINCT FROM 'RENEWED'
    OR p.maturity_policy IS DISTINCT FROM 'MANUAL_REDEEM_RENEW'
    OR ROW(o.tenant_id,o.user_id,o.wallet_id,o.asset_code,o.principal,o.months,o.annual_rate,o.timezone,o.config_revision,o.maturity_policy)
      IS DISTINCT FROM ROW(p.tenant_id,p.user_id,p.wallet_id,p.asset_code,p.principal,p.months,p.annual_rate,p.timezone,p.config_revision,p.maturity_policy)
    OR o.started_at IS DISTINCT FROM p.redeem_before THEN RAISE EXCEPTION 'Wealth renewal contract mismatch'; END IF;
 END IF;
 SELECT * INTO n FROM wealth_orders WHERE previous_order_id=o.id;
 IF (o.close_reason IS NOT DISTINCT FROM 'RENEWED') <> (n.id IS NOT NULL) THEN RAISE EXCEPTION 'Wealth renewal successor missing or unexpected'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER wealth_renewal_evidence AFTER INSERT OR UPDATE ON wealth_orders
 DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_wealth_renewal();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Wealth renewal contracts and financial history are immutable.');
    }
};
