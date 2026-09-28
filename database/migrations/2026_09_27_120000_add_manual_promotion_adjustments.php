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
        Schema::create('manual_promotion_adjustments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('user_id');
            $t->uuid('actor_id');
            $t->string('actor_name');
            $t->uuid('request_id');
            $t->uuid('expected_adjustment_id')->nullable();
            $t->string('choice', 40);
            $t->uuid('level_id')->nullable();
            $t->integer('rank')->nullable(); // null restores normal paid qualification; 0 overrides it to ordinary.
            $t->integer('previous_rank');
            $t->integer('effective_rank');
            $t->integer('revision');
            $t->integer('percent');
            $t->integer('reward');
            $t->string('reason', 500);
            $t->timestampTz('created_at', 6);
            $t->unique(['id', 'tenant_id']);
            $t->unique(['id', 'tenant_id', 'user_id'], 'manual_promotion_owner_unique');
            $t->unique(['tenant_id', 'user_id', 'request_id'], 'manual_promotion_retry_unique');
            $t->index(['tenant_id', 'user_id', 'created_at'], 'manual_promotion_user_history');
            $t->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            $t->foreign(['level_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('paid_promotion_levels');
            $t->foreign('actor_id')->references('id')->on('admin_users');
        });
        DB::statement('ALTER TABLE manual_promotion_adjustments ADD CHECK ((rank IS NULL OR rank >= 0) AND previous_rank >= 0 AND effective_rank >= 0 AND revision > 0 AND percent BETWEEN 0 AND 100 AND reward >= 20)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_manual_promotion_history() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Manual promotion adjustments are immutable'; END $$;
            CREATE TRIGGER manual_promotion_history_guard BEFORE UPDATE OR DELETE ON manual_promotion_adjustments
            FOR EACH ROW EXECUTE FUNCTION guard_manual_promotion_history();
            SQL);
        Schema::table('paid_promotion_shares', function (Blueprint $t): void {
            $t->uuid('manual_adjustment_id')->nullable();
            $t->foreign(['manual_adjustment_id', 'tenant_id', 'user_id'], 'promotion_share_manual_fk')->references(['id', 'tenant_id', 'user_id'])->on('manual_promotion_adjustments');
        });
        // Keep the database's activation-evidence guard aligned with the effective-rank reader.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION effective_promotion_rank(company uuid, account uuid, at_time timestamptz)
            RETURNS integer LANGUAGE sql STABLE AS $$
                SELECT coalesce(
                    (SELECT rank FROM manual_promotion_adjustments WHERE tenant_id=company AND user_id=account
                        AND created_at<=at_time ORDER BY created_at DESC,id DESC LIMIT 1),
                    (SELECT rank FROM paid_promotion_cycles WHERE tenant_id=company AND user_id=account
                        AND starts_at<=at_time AND ends_at>at_time ORDER BY starts_at DESC LIMIT 1),0)
            $$;
            CREATE OR REPLACE FUNCTION validate_activation_count_rank_insert() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE act account_activations%ROWTYPE;
            BEGIN
                SELECT * INTO act FROM account_activations WHERE id=NEW.activation_id AND tenant_id=NEW.tenant_id;
                IF NEW.ancestor_rank <> effective_promotion_rank(act.tenant_id,NEW.ancestor_user_id,act.activated_at)
                    OR NEW.source_rank <> effective_promotion_rank(act.tenant_id,act.user_id,act.activated_at) THEN
                    RAISE EXCEPTION 'Activation counting ranks must match effective qualification' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END $$;
            SQL);
        $name = 'promotion_members.manage';
        $id = DB::table('permissions')->where('name', $name)->value('id');
        if (! $id) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $role) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        if (DB::table('manual_promotion_adjustments')->exists()) {
            throw new RuntimeException('Manual promotion history must be retained. Restore paid rules instead of rolling back.');
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_activation_count_rank_insert() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE act account_activations%ROWTYPE; ancestor_rank_at_activation integer; source_rank_at_activation integer;
            BEGIN
                SELECT * INTO act FROM account_activations WHERE id=NEW.activation_id AND tenant_id=NEW.tenant_id;
                SELECT rank INTO ancestor_rank_at_activation FROM paid_promotion_cycles
                    WHERE tenant_id=act.tenant_id AND user_id=NEW.ancestor_user_id
                    AND starts_at<=act.activated_at AND ends_at>act.activated_at ORDER BY starts_at DESC LIMIT 1;
                SELECT rank INTO source_rank_at_activation FROM paid_promotion_cycles
                    WHERE tenant_id=act.tenant_id AND user_id=act.user_id
                    AND starts_at<=act.activated_at AND ends_at>act.activated_at ORDER BY starts_at DESC LIMIT 1;
                IF NEW.ancestor_rank <> coalesce(ancestor_rank_at_activation,0) OR NEW.source_rank <> coalesce(source_rank_at_activation,0) THEN
                    RAISE EXCEPTION 'Activation counting ranks must match effective qualification' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END $$;
            SQL);
        DB::statement('DROP FUNCTION IF EXISTS effective_promotion_rank(uuid,uuid,timestamptz)');
        Schema::table('paid_promotion_shares', function (Blueprint $t): void {
            $t->dropForeign('promotion_share_manual_fk');
            $t->dropColumn('manual_adjustment_id');
        });
        Schema::dropIfExists('manual_promotion_adjustments');
        DB::statement('DROP FUNCTION IF EXISTS guard_manual_promotion_history()');
    }
};
