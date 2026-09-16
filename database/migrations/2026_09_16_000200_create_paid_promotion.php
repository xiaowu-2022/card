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
        DB::statement('ALTER TABLE commission_awards ALTER COLUMN level_id DROP NOT NULL');
        DB::statement('CREATE UNIQUE INDEX ledger_entries_paid_scope ON ledger_entries(id,tenant_id)');
        DB::statement('ALTER TABLE ledger_accounts DROP CONSTRAINT ledger_accounts_type_check');
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_check CHECK (account_type IN ('USER_AVAILABLE','USER_SECURITY_DEPOSIT','USER_WITHDRAWAL_HOLD','USER_CARD_ISSUE_HOLD','USER_CARD_FUNDING_HOLD','USER_COMMISSION','TENANT_TOPUP_CLEARING','TENANT_WITHDRAWAL_CLEARING','TENANT_CARD_FUNDING_CLEARING','TENANT_FEE_REVENUE','TENANT_COMMISSION_CLEARING','TENANT_EXCHANGE_CLEARING','TENANT_PROMOTION_FEE_REVENUE'))");
        Schema::create('paid_promotion_levels', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->integer('rank');
            $t->decimal('fee', 20, 8);
            $t->integer('percent');
            $t->integer('reward');
            $t->integer('target');
            $t->integer('revision')->default(1);
            $t->boolean('enabled')->default(true);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'rank']);
            $t->unique(['id', 'tenant_id']);
        });
        DB::statement('ALTER TABLE paid_promotion_levels ADD CHECK (rank BETWEEN 1 AND 8 AND fee > 0 AND percent BETWEEN 0 AND 100 AND reward >= 20 AND target > 0 AND revision > 0)');
        Schema::create('paid_promotion_cycles', function (Blueprint $t): void {
            $this->owned($t);
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->uuid('level_id');
            $t->integer('revision');
            $t->integer('rank');
            $t->decimal('tariff', 20, 8);
            $t->integer('percent');
            $t->integer('reward');
            $t->integer('target');
            $t->foreign(['level_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('paid_promotion_levels');
            $t->index(['tenant_id', 'user_id', 'ends_at']);
        });
        DB::statement('ALTER TABLE paid_promotion_cycles ADD CHECK (ends_at > starts_at AND rank BETWEEN 1 AND 8 AND tariff > 0 AND percent BETWEEN 0 AND 100 AND reward >= 20 AND target > 0)');
        Schema::create('paid_promotion_orders', function (Blueprint $t): void {
            $this->owned($t);
            $t->uuid('request_id');
            $t->uuid('cycle_id')->nullable();
            $t->uuid('level_id');
            $t->integer('revision');
            $t->integer('rank');
            $t->decimal('tariff', 20, 8);
            $t->integer('percent');
            $t->integer('reward');
            $t->integer('target');
            $t->decimal('amount', 20, 8);
            $t->decimal('previous_tariff', 20, 8);
            $t->string('status', 20)->default('QUOTED');
            $t->timestampTz('expires_at');
            $t->timestampTz('completed_at')->nullable();
            $t->uuid('ledger_entry_id')->nullable();
            $t->unique(['tenant_id', 'user_id', 'request_id']);
            $t->foreign(['cycle_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('paid_promotion_cycles');
            $t->foreign(['level_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('paid_promotion_levels');
            $t->foreign(['ledger_entry_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('ledger_entries');
        });
        DB::statement("ALTER TABLE paid_promotion_orders ADD CHECK (amount > 0 AND previous_tariff >= 0 AND amount = tariff - previous_tariff AND status IN ('QUOTED','COMPLETED') AND ((status='COMPLETED')=(completed_at IS NOT NULL AND ledger_entry_id IS NOT NULL AND cycle_id IS NOT NULL)))");
        Schema::create('paid_promotion_events', function (Blueprint $t): void {
            $this->owned($t);
            $t->string('kind', 16);
            $t->uuid('source_id');
            $t->integer('source_rank');
            $t->decimal('amount', 20, 8);
            $t->timestampTz('occurred_at');
            $t->unique(['tenant_id', 'kind', 'source_id']);
        });
        Schema::create('paid_promotion_shares', function (Blueprint $t): void {
            $this->owned($t);
            $t->uuid('event_id');
            $t->integer('depth');
            $t->integer('rank');
            $t->uuid('cycle_id')->nullable();
            $t->integer('revision')->nullable();
            $t->integer('standard');
            $t->integer('covered');
            $t->foreign(['cycle_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('paid_promotion_cycles');
            $t->decimal('rate', 20, 8);
            $t->decimal('amount', 20, 8);
            $t->uuid('ledger_entry_id')->nullable();
            $t->foreign(['event_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('paid_promotion_events');
            $t->foreign(['ledger_entry_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('ledger_entries');
            $t->unique(['tenant_id', 'event_id', 'user_id']);
            $t->index(['tenant_id', 'user_id', 'created_at']);
        });
        DB::statement("ALTER TABLE paid_promotion_events ADD CHECK (kind IN ('ANNUAL','ACTIVATION') AND source_rank BETWEEN 0 AND 8 AND amount > 0)");
        DB::statement('ALTER TABLE paid_promotion_shares ADD CHECK (standard >= 0 AND covered >= 0 AND rate = GREATEST(0,standard-covered) AND depth > 0 AND rank BETWEEN 0 AND 8 AND rate >= 0 AND amount >= 0 AND ((amount > 0)=(ledger_entry_id IS NOT NULL)))');
        Schema::create('paid_promotion_rebates', function (Blueprint $t): void {
            $this->owned($t);
            $t->uuid('cycle_id');
            $t->uuid('request_id');
            $t->integer('rank');
            $t->integer('target');
            $t->bigInteger('direct_count');
            $t->bigInteger('indirect_count');
            $t->decimal('amount', 20, 8);
            $t->string('status', 20)->default('PENDING');
            $t->uuid('ledger_entry_id')->nullable();
            $t->foreignUuid('reviewer_id')->nullable()->constrained('admin_users');
            $t->timestampTz('reviewed_at')->nullable();
            $t->string('reason', 300)->nullable();
            $t->unique(['tenant_id', 'user_id', 'request_id']);
            $t->foreign(['cycle_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('paid_promotion_cycles');
            $t->foreign(['ledger_entry_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('ledger_entries');
        });
        DB::statement("CREATE UNIQUE INDEX paid_rebate_pending ON paid_promotion_rebates(cycle_id) WHERE status='PENDING'");
        DB::statement("ALTER TABLE paid_promotion_rebates ADD CHECK (amount > 0 AND target > 0 AND direct_count >= 0 AND indirect_count >= 0 AND 2*direct_count+indirect_count >= 2*target AND status IN ('PENDING','APPROVED','REJECTED','WITHDRAWN') AND ((status='APPROVED')=(ledger_entry_id IS NOT NULL)) AND ((status IN ('APPROVED','REJECTED'))=(reviewer_id IS NOT NULL AND reviewed_at IS NOT NULL)))");
        Schema::create('promotion_assignment_archive', function (Blueprint $t): void {
            $t->uuid('member_id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('level_id');
            $t->timestampTz('archived_at');
            $t->foreign(['member_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('promotion_members');
            $t->foreign(['level_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('promotion_levels');
        });
        DB::statement('INSERT INTO promotion_assignment_archive SELECT id, tenant_id, level_id, CURRENT_TIMESTAMP FROM promotion_members WHERE level_id IS NOT NULL');
        DB::statement('UPDATE promotion_members SET level_id=NULL WHERE level_id IS NOT NULL');
        DB::statement('ALTER TABLE promotion_members ADD CONSTRAINT promotion_paid_assignment_only CHECK (level_id IS NULL)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION initialize_paid_promotion() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
              INSERT INTO paid_promotion_levels (id,tenant_id,rank,fee,percent,reward,target,revision,enabled,created_at,updated_at)
              SELECT gen_random_uuid(),NEW.id,v.*,1,true,now(),now() FROM (VALUES
              (1,1000,30,50,100),(2,2000,40,60,135),(3,5000,50,70,250),(4,10000,60,80,400),
              (5,20000,70,90,666),(6,50000,80,100,1428),(7,100000,90,110,2500),(8,200000,100,120,4000)) v(rank,fee,percent,reward,target);
              RETURN NEW;
            END $$;
            CREATE TRIGGER initialize_paid_promotion AFTER INSERT ON tenants FOR EACH ROW EXECUTE FUNCTION initialize_paid_promotion();
            INSERT INTO paid_promotion_levels (id,tenant_id,rank,fee,percent,reward,target,revision,enabled,created_at,updated_at)
            SELECT gen_random_uuid(),t.id,v.*,1,true,now(),now() FROM tenants t CROSS JOIN (VALUES
              (1,1000,30,50,100),(2,2000,40,60,135),(3,5000,50,70,250),(4,10000,60,80,400),
              (5,20000,70,90,666),(6,50000,80,100,1428),(7,100000,90,110,2500),(8,200000,100,120,4000)) v(rank,fee,percent,reward,target);
            CREATE OR REPLACE FUNCTION protect_paid_promotion() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
              IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Paid promotion history is immutable'; END IF;
              IF TG_TABLE_NAME='paid_promotion_levels' THEN
                IF (NEW.id,NEW.tenant_id,NEW.rank,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.rank,OLD.created_at) OR NEW.revision <> OLD.revision+1 THEN RAISE EXCEPTION 'Invalid tariff revision'; END IF;
              ELSIF TG_TABLE_NAME='paid_promotion_cycles' THEN
                IF (NEW.id,NEW.tenant_id,NEW.user_id,NEW.starts_at,NEW.ends_at,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.tenant_id,OLD.user_id,OLD.starts_at,OLD.ends_at,OLD.created_at) OR NEW.rank <= OLD.rank OR NEW.tariff <= OLD.tariff THEN RAISE EXCEPTION 'Invalid cycle upgrade'; END IF;
              ELSIF TG_TABLE_NAME='paid_promotion_orders' THEN
                IF OLD.status <> 'QUOTED' OR NEW.status <> 'COMPLETED' OR (to_jsonb(NEW)-ARRAY['status','completed_at','cycle_id','ledger_entry_id']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','completed_at','cycle_id','ledger_entry_id']) OR (OLD.cycle_id IS NOT NULL AND OLD.cycle_id IS DISTINCT FROM NEW.cycle_id) THEN RAISE EXCEPTION 'Immutable fee order'; END IF;
              ELSE
                IF OLD.status <> 'PENDING' OR NEW.status NOT IN ('APPROVED','REJECTED','WITHDRAWN') OR (to_jsonb(NEW)-ARRAY['status','ledger_entry_id','reviewer_id','reviewed_at','reason']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','ledger_entry_id','reviewer_id','reviewed_at','reason']) THEN RAISE EXCEPTION 'Immutable rebate snapshot'; END IF;
              END IF;
              RETURN NEW;
            END $$;
            SQL);
        foreach (['paid_promotion_levels', 'paid_promotion_cycles', 'paid_promotion_orders', 'paid_promotion_rebates'] as $table) {
            DB::statement("CREATE TRIGGER protect_{$table} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION protect_paid_promotion()");
        }
        foreach (['paid_promotion_events', 'paid_promotion_shares', 'promotion_assignment_archive'] as $table) {
            DB::statement("CREATE TRIGGER protect_{$table} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_ledger_history_mutation()");
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_paid_promotion_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE e ledger_entries%ROWTYPE; evt paid_promotion_events%ROWTYPE; valid_count integer; expected_key text; expected_type text; expected_account text; expected_reference text; amount_value numeric; owner_id uuid; cycle paid_promotion_cycles%ROWTYPE;
            BEGIN
              IF TG_TABLE_NAME='paid_promotion_cycles' THEN
                IF EXISTS(SELECT 1 FROM paid_promotion_cycles c WHERE c.tenant_id=NEW.tenant_id AND c.user_id=NEW.user_id AND c.id<>NEW.id AND c.starts_at<NEW.ends_at AND c.ends_at>NEW.starts_at)
                OR NOT EXISTS(SELECT 1 FROM paid_promotion_orders o WHERE o.tenant_id=NEW.tenant_id AND o.user_id=NEW.user_id AND o.cycle_id=NEW.id AND o.status='COMPLETED' AND o.rank=NEW.rank AND o.tariff=NEW.tariff) THEN RAISE EXCEPTION 'Qualification requires a unique paid period'; END IF;
                RETURN NULL;
              END IF;
              IF TG_TABLE_NAME='paid_promotion_events' THEN
                IF NEW.kind='ANNUAL' THEN
                  IF NOT EXISTS(SELECT 1 FROM paid_promotion_orders o WHERE o.id=NEW.source_id AND o.tenant_id=NEW.tenant_id AND o.user_id=NEW.user_id AND o.amount=NEW.amount AND o.rank=NEW.source_rank AND o.status='COMPLETED') THEN RAISE EXCEPTION 'Missing annual source'; END IF;
                ELSE
                  IF NOT EXISTS(SELECT 1 FROM promotion_funding_events f WHERE f.id=NEW.source_id AND f.tenant_id=NEW.tenant_id AND f.user_id=NEW.user_id AND f.amount=NEW.amount) THEN RAISE EXCEPTION 'Missing activation source'; END IF;
                END IF;
                RETURN NULL;
              END IF;
              IF TG_TABLE_NAME='paid_promotion_orders' THEN IF NEW.status<>'COMPLETED' THEN RETURN NULL; END IF; END IF;
              IF TG_TABLE_NAME='paid_promotion_rebates' THEN IF NEW.status<>'APPROVED' THEN RETURN NULL; END IF; END IF;
              IF TG_TABLE_NAME='paid_promotion_shares' THEN IF NEW.amount=0 THEN RETURN NULL; END IF; END IF;
              SELECT * INTO e FROM ledger_entries WHERE id=NEW.ledger_entry_id AND tenant_id=NEW.tenant_id;
              owner_id:=NEW.user_id; amount_value:=NEW.amount;
              IF TG_TABLE_NAME='paid_promotion_orders' THEN
                expected_key:='promotion_fee:'||NEW.id::text; expected_type:='PROMOTION_ANNUAL_FEE'; expected_reference:='PROMOTION_ORDER'; expected_account:='TENANT_PROMOTION_FEE_REVENUE';
                SELECT * INTO cycle FROM paid_promotion_cycles WHERE id=NEW.cycle_id AND tenant_id=NEW.tenant_id AND user_id=NEW.user_id;
                IF cycle.id IS NULL OR NEW.completed_at<cycle.starts_at OR NEW.completed_at>=cycle.ends_at THEN RAISE EXCEPTION 'Invalid paid period ownership'; END IF;
              ELSIF TG_TABLE_NAME='paid_promotion_rebates' THEN
                expected_key:='promotion_rebate:'||NEW.id::text; expected_type:='PROMOTION_FEE_REBATE'; expected_reference:='PROMOTION_REBATE'; expected_account:='TENANT_PROMOTION_FEE_REVENUE'; amount_value:=-NEW.amount;
                IF (SELECT COALESCE(SUM(amount),0) FROM paid_promotion_rebates WHERE tenant_id=NEW.tenant_id AND cycle_id=NEW.cycle_id AND status='APPROVED') > (SELECT COALESCE(SUM(amount),0) FROM paid_promotion_orders WHERE tenant_id=NEW.tenant_id AND user_id=NEW.user_id AND cycle_id=NEW.cycle_id AND status='COMPLETED') THEN RAISE EXCEPTION 'Annual rebate exceeds paid amount'; END IF;
              ELSE
                SELECT * INTO evt FROM paid_promotion_events WHERE id=NEW.event_id AND tenant_id=NEW.tenant_id;
                expected_key:=CASE WHEN evt.kind='ANNUAL' THEN 'promotion_annual_award:' ELSE 'commission_award:' END || NEW.id::text;
                expected_reference:=CASE WHEN evt.kind='ANNUAL' THEN 'PROMOTION_ANNUAL_AWARD' ELSE 'COMMISSION_AWARD' END;
                expected_type:=CASE WHEN evt.kind='ANNUAL' THEN 'PROMOTION_ANNUAL_COMMISSION' ELSE 'COMMISSION_EARN' END; expected_account:='TENANT_COMMISSION_CLEARING'; amount_value:=-NEW.amount;
              END IF;
              SELECT COUNT(*) INTO valid_count FROM ledger_postings p JOIN ledger_accounts a ON a.id=p.ledger_account_id AND a.tenant_id=p.tenant_id
                WHERE p.tenant_id=NEW.tenant_id AND p.ledger_entry_id=e.id AND ((a.account_type=expected_account AND a.user_id IS NULL AND p.delta=amount_value)
                OR (a.account_type=CASE WHEN TG_TABLE_NAME='paid_promotion_shares' THEN 'USER_COMMISSION' ELSE 'USER_AVAILABLE' END AND a.user_id=owner_id AND p.delta=-amount_value));
              IF e.id IS NULL OR e.sealed_at IS NULL OR e.asset_code<>'USDT' OR e.event_key<>expected_key OR e.event_type<>expected_type OR e.reference_type IS DISTINCT FROM expected_reference OR e.reference_id IS DISTINCT FROM NEW.id OR valid_count<>2 OR (SELECT COUNT(*) FROM ledger_postings WHERE ledger_entry_id=e.id)<>2 THEN RAISE EXCEPTION 'Paid promotion requires exact sealed accounting evidence'; END IF;
              RETURN NULL;
            END $$;
            SQL);
        foreach (['paid_promotion_cycles', 'paid_promotion_orders', 'paid_promotion_events', 'paid_promotion_shares', 'paid_promotion_rebates'] as $table) {
            DB::statement("CREATE CONSTRAINT TRIGGER evidence_{$table} AFTER INSERT OR UPDATE ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_paid_promotion_evidence()");
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION paid_activation_award_evidence() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
              IF NEW.level_id IS NULL AND NOT EXISTS (
                SELECT 1 FROM paid_promotion_shares s JOIN paid_promotion_events e ON e.id=s.event_id AND e.tenant_id=s.tenant_id
                WHERE s.id=NEW.id AND s.tenant_id=NEW.tenant_id AND s.user_id=NEW.user_id AND s.amount=NEW.amount
                  AND s.ledger_entry_id=NEW.ledger_entry_id AND s.standard=NEW.level_reward AND e.kind='ACTIVATION' AND e.source_id=NEW.funding_event_id
              ) THEN RAISE EXCEPTION 'Activation award requires immutable paid promotion evidence'; END IF;
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER paid_activation_award_evidence AFTER INSERT ON commission_awards DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION paid_activation_award_evidence();
            SQL);
        $permission = (string) Str::uuid();
        DB::table('permissions')->insert(['id' => $permission, 'name' => 'promotion_refunds.review', 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('roles')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $role) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    private function owned(Blueprint $t): void
    {
        $t->uuid('id')->primary();
        $t->foreignUuid('tenant_id')->constrained('tenants');
        $t->uuid('user_id');
        $t->timestampTz('created_at')->useCurrent();
        $t->unique(['id', 'tenant_id']);
        $t->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
    }

    public function down(): void
    {
        throw new RuntimeException('Paid financial history cannot be rolled back; use a reviewed forward migration.');
    }
};
