<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE wallet_topup_orders ADD COLUMN manual_confirmed_at timestamptz NULL, ADD COLUMN manual_confirmed_by uuid NULL REFERENCES admin_users(id), ADD COLUMN manual_confirmation_request_id uuid NULL');
        DB::statement("ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_manual_confirmation_check CHECK (
            (manual_confirmed_at IS NULL AND manual_confirmed_by IS NULL AND manual_confirmation_request_id IS NULL)
            OR (manual_confirmed_at IS NOT NULL AND manual_confirmed_by IS NOT NULL AND manual_confirmation_request_id IS NOT NULL
                AND payment_rail IS NOT NULL AND payment_rail = 'TRC20_SHARED' AND asset_code = 'USDT'
                AND status IN ('PAID','CREDITED') AND blockchain_confirmed_at IS NULL)
        )");
        DB::statement('CREATE UNIQUE INDEX topup_manual_confirmation_request_unique ON wallet_topup_orders(manual_confirmed_by, manual_confirmation_request_id) WHERE manual_confirmed_by IS NOT NULL');
        DB::statement('ALTER TABLE wallet_topup_orders DROP CONSTRAINT topup_trc20_paid_check');
        DB::statement("ALTER TABLE wallet_topup_orders ADD CONSTRAINT topup_trc20_paid_check CHECK (
            payment_rail IS NULL OR status NOT IN ('PAID','CREDITED') OR blockchain_confirmed_at IS NOT NULL OR manual_confirmed_at IS NOT NULL
        )");
        // Unproven manual receipts keep their exact amount reserved against late transfers.
        DB::statement('DROP INDEX topup_trc20_active_amount_unique');
        DB::statement("CREATE UNIQUE INDEX topup_trc20_active_amount_unique ON wallet_topup_orders(deposit_address, expected_amount)
            WHERE payment_rail = 'TRC20_SHARED' AND (status IN ('PENDING','PROCESSING','UNKNOWN','PAID','REQUIRES_REVIEW') OR manual_confirmed_at IS NOT NULL)");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_topup_manual_confirmation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.manual_confirmed_at IS NOT NULL THEN RAISE EXCEPTION 'Manual confirmation requires an existing order'; END IF;
                ELSIF OLD.manual_confirmed_at IS NOT NULL THEN
                    IF NEW.manual_confirmed_at IS DISTINCT FROM OLD.manual_confirmed_at
                        OR NEW.manual_confirmed_by IS DISTINCT FROM OLD.manual_confirmed_by
                        OR NEW.manual_confirmation_request_id IS DISTINCT FROM OLD.manual_confirmation_request_id THEN
                        RAISE EXCEPTION 'Manual confirmation is immutable';
                    END IF;
                ELSIF NEW.manual_confirmed_at IS NOT NULL THEN
                    IF OLD.status NOT IN ('PENDING','PROCESSING','UNKNOWN') OR NEW.status <> 'PAID' THEN
                        RAISE EXCEPTION 'Invalid manual confirmation transition';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER topup_manual_confirmation_immutable BEFORE INSERT OR UPDATE ON wallet_topup_orders
                FOR EACH ROW EXECUTE FUNCTION protect_topup_manual_confirmation();
            SQL);
        $id = (string) Str::uuid();
        DB::table('permissions')->insert(['id' => $id, 'name' => 'wallet_topups.confirm', 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        throw new LogicException('Manual receipt confirmations are immutable financial history. Use a forward migration.');
    }
};
