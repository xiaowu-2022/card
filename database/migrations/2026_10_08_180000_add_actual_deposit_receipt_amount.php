<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['wallet_topup_orders', 'asset_deposit_orders'] as $table) {
            $precision = $table === 'asset_deposit_orders' ? '30,18' : '20,8';
            DB::statement("ALTER TABLE {$table} ADD COLUMN actual_received_amount numeric({$precision}) NULL,
                ADD CONSTRAINT {$table}_actual_receipt CHECK (actual_received_amount IS NULL OR
                    (actual_received_amount > 0 AND actual_received_amount < 1000000000000 AND manual_confirmed_at IS NOT NULL))");
        }
        $this->assetGuard(true);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_actual_deposit_receipt() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
                IF (OLD.manual_confirmed_at IS NOT NULL OR OLD.status='CREDITED') AND
                    NEW.actual_received_amount IS DISTINCT FROM OLD.actual_received_amount
                THEN RAISE EXCEPTION 'Actual deposit receipt is immutable'; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER actual_receipt_immutable BEFORE UPDATE ON wallet_topup_orders FOR EACH ROW EXECUTE FUNCTION protect_actual_deposit_receipt();
            CREATE TRIGGER actual_receipt_immutable BEFORE UPDATE ON asset_deposit_orders FOR EACH ROW EXECUTE FUNCTION protect_actual_deposit_receipt();
            SQL);
    }

    public function down(): void
    {
        $this->assetGuard(false);
        foreach (['wallet_topup_orders', 'asset_deposit_orders'] as $table) {
            DB::statement("DROP TRIGGER actual_receipt_immutable ON {$table}");
            DB::statement("ALTER TABLE {$table} DROP COLUMN actual_received_amount");
        }
        DB::statement('DROP FUNCTION protect_actual_deposit_receipt()');
    }

    private function assetGuard(bool $enable): void
    {
        $definition = DB::selectOne("SELECT pg_get_functiondef('protect_asset_order()'::regprocedure) AS definition")->definition;
        $old = "'manual_receipt_type','advance_journal_id',";
        $new = "'manual_receipt_type','advance_journal_id','actual_received_amount',";
        DB::unprepared(str_replace($enable ? $old : $new, $enable ? $new : $old, $definition));
    }
};
