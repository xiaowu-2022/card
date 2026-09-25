<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_configurations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->uuid('user_id');
            $t->boolean('enabled')->default(false);
            $t->decimal('share_percent', 12, 8);
            $t->foreignUuid('updated_by')->constrained('admin_users');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'user_id']);
            $t->unique(['id', 'tenant_id']);
            $t->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
        });
        Schema::create('partner_journal_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('partner_id');
            $t->string('kind', 20);
            $t->decimal('amount', 28, 8);
            $t->date('business_date');
            $t->text('note');
            $t->uuid('reverses_id')->nullable()->unique();
            $t->foreignUuid('actor_id')->constrained('admin_users');
            $t->uuid('request_id');
            $t->string('request_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['tenant_id', 'request_id']);
            $t->unique(['id', 'tenant_id', 'partner_id']);
            $t->foreign(['partner_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('partner_configurations');
            $t->foreign(['reverses_id', 'tenant_id', 'partner_id'])->references(['id', 'tenant_id', 'partner_id'])->on('partner_journal_entries');
        });
        Schema::table('asset_withdrawal_orders', fn (Blueprint $t) => $t->unique(['id', 'tenant_id'], 'asset_withdrawal_partner_identity'));
        Schema::create('withdrawal_fee_valuations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('withdrawal_id');
            $t->string('asset_code', 8);
            $t->decimal('original_amount', 38, 18);
            $t->decimal('rate', 38, 18)->nullable();
            $t->decimal('usdt_amount', 38, 8)->nullable();
            $t->timestampTz('observed_at')->nullable();
            $t->string('source', 20);
            $t->text('evidence')->nullable();
            $t->foreignUuid('actor_id')->nullable()->constrained('admin_users');
            $t->uuid('request_id')->nullable();
            $t->timestampTz('created_at');
            $t->timestampTz('valued_at')->nullable();
            $t->unique(['tenant_id', 'withdrawal_id']);
            $t->foreign(['withdrawal_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('asset_withdrawal_orders');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE partner_configurations ADD CHECK(share_percent BETWEEN 0 AND 100);
            ALTER TABLE partner_journal_entries ADD CHECK(kind IN ('REIMBURSEMENT','ADVANCE') AND amount>0);
            ALTER TABLE withdrawal_fee_valuations ADD CHECK(original_amount>=0 AND source IN ('PENDING','OKX','MANUAL','PARITY') AND
              ((source='PENDING' AND rate IS NULL AND usdt_amount IS NULL AND valued_at IS NULL) OR
              (source<>'PENDING' AND rate>0 AND usdt_amount>=0 AND observed_at IS NOT NULL AND valued_at IS NOT NULL)));
            CREATE OR REPLACE FUNCTION protect_partner_journal() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
              RAISE EXCEPTION 'Cooperation journal is append-only; create a reversal'; END $$;
            CREATE TRIGGER partner_journal_immutable BEFORE UPDATE OR DELETE ON partner_journal_entries FOR EACH ROW EXECUTE FUNCTION protect_partner_journal();
            CREATE OR REPLACE FUNCTION protect_fee_valuation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
              IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Fee valuation cannot be deleted'; END IF;
              IF OLD.source<>'PENDING' OR NEW.source<>'MANUAL' OR NEW.actor_id IS NULL OR NEW.evidence IS NULL OR NEW.request_id IS NULL
                OR ROW(NEW.id,NEW.tenant_id,NEW.withdrawal_id,NEW.asset_code,NEW.original_amount,NEW.created_at) IS DISTINCT FROM
                   ROW(OLD.id,OLD.tenant_id,OLD.withdrawal_id,OLD.asset_code,OLD.original_amount,OLD.created_at)
              THEN RAISE EXCEPTION 'Fixed fee valuation is immutable'; END IF;
              RETURN NEW; END $$;
            CREATE TRIGGER fee_valuation_immutable BEFORE UPDATE OR DELETE ON withdrawal_fee_valuations FOR EACH ROW EXECUTE FUNCTION protect_fee_valuation();
            SQL);
        $permission = DB::table('permissions')->where('name', 'partners.manage')->value('id');
        if (! $permission) {
            $permission = (string) Str::uuid();
            DB::table('permissions')->insert(['id' => $permission, 'name' => 'partners.manage', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('roles')->where('name', 'PLATFORM_OWNER')->where('scope_type', 'PLATFORM')->pluck('id') as $role) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_fee_valuations');
        Schema::table('asset_withdrawal_orders', fn (Blueprint $t) => $t->dropUnique('asset_withdrawal_partner_identity'));
        Schema::dropIfExists('partner_journal_entries');
        Schema::dropIfExists('partner_configurations');
        DB::unprepared('DROP FUNCTION IF EXISTS protect_partner_journal(); DROP FUNCTION IF EXISTS protect_fee_valuation();');
    }
};
