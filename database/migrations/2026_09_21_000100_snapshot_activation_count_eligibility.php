<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing immutable activations retain their original unrestricted counting policy.
        DB::statement("ALTER TABLE account_activations ADD COLUMN counting_policy varchar(32) NOT NULL DEFAULT 'LEGACY'");
        DB::statement("ALTER TABLE account_activations ALTER COLUMN counting_policy SET DEFAULT 'FIVE_GENERATION_SNAPSHOT'");
        DB::statement("ALTER TABLE account_activations ADD CHECK (counting_policy IN ('LEGACY','FIVE_GENERATION_SNAPSHOT'))");
        Schema::create('activation_count_snapshots', function (Blueprint $t): void {
            $t->uuid('activation_id');
            $t->uuid('tenant_id');
            $t->uuid('ancestor_user_id');
            $t->integer('depth');
            $t->integer('ancestor_rank');
            $t->integer('source_rank');
            $t->integer('path_max_rank');
            $t->boolean('eligible');
            $t->string('reason', 32);
            $t->primary(['activation_id', 'ancestor_user_id']);
            $t->unique(['activation_id', 'depth']);
            $t->foreign(['activation_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('account_activations');
            $t->foreign(['ancestor_user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            $t->index(['tenant_id', 'ancestor_user_id']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE activation_count_snapshots ADD CHECK(depth>0 AND ancestor_rank>=0 AND source_rank>=0 AND path_max_rank>=source_rank);
            ALTER TABLE activation_count_snapshots ADD CHECK(
                (depth>5 AND NOT eligible AND reason='BEYOND_FIVE_GENERATIONS') OR
                (depth<=5 AND ancestor_rank<=path_max_rank AND NOT eligible AND reason='SAME_OR_HIGHER_RANK') OR
                (depth<=5 AND ancestor_rank>path_max_rank AND eligible AND reason='ELIGIBLE'));
            CREATE TRIGGER activation_count_snapshots_immutable BEFORE UPDATE OR DELETE ON activation_count_snapshots
                FOR EACH ROW EXECUTE FUNCTION reject_ledger_history_mutation();
            CREATE OR REPLACE FUNCTION require_new_activation_count_policy() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.counting_policy <> 'FIVE_GENERATION_SNAPSHOT' THEN
                    RAISE EXCEPTION 'New activations require snapshot counting' USING ERRCODE='23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER require_new_activation_count_policy BEFORE INSERT ON account_activations
                FOR EACH ROW EXECUTE FUNCTION require_new_activation_count_policy();
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
            CREATE TRIGGER activation_count_rank_insert BEFORE INSERT ON activation_count_snapshots
                FOR EACH ROW EXECUTE FUNCTION validate_activation_count_rank_insert();
            CREATE OR REPLACE FUNCTION validate_activation_count_snapshots() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE act account_activations%ROWTYPE;
            BEGIN
                IF TG_TABLE_NAME='account_activations' THEN
                    SELECT * INTO act FROM account_activations WHERE id=NEW.id;
                ELSE
                    SELECT * INTO act FROM account_activations WHERE id=NEW.activation_id AND tenant_id=NEW.tenant_id;
                END IF;
                IF act.counting_policy <> 'FIVE_GENERATION_SNAPSHOT' THEN
                    RAISE EXCEPTION 'Legacy activations cannot receive new counting snapshots' USING ERRCODE='23514';
                END IF;
                IF EXISTS(
                    SELECT 1 FROM
                    (SELECT * FROM account_activation_relations WHERE activation_id=act.id AND tenant_id=act.tenant_id) r
                    FULL JOIN (SELECT * FROM activation_count_snapshots WHERE activation_id=act.id AND tenant_id=act.tenant_id) s
                    ON r.ancestor_user_id=s.ancestor_user_id AND r.depth=s.depth
                    WHERE r.activation_id IS NULL OR s.activation_id IS NULL
                ) OR EXISTS(
                    SELECT 1 FROM activation_count_snapshots s WHERE s.activation_id=act.id AND (
                        s.path_max_rank <> greatest(s.source_rank, coalesce((SELECT max(p.ancestor_rank)
                            FROM activation_count_snapshots p WHERE p.activation_id=s.activation_id AND p.depth<s.depth),0))
                        OR s.source_rank <> (SELECT p.source_rank FROM activation_count_snapshots p WHERE p.activation_id=s.activation_id AND p.depth=1)
                    )
                ) THEN
                    RAISE EXCEPTION 'Activation counting snapshot evidence mismatch' USING ERRCODE='23514';
                END IF;
                RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER activation_count_evidence AFTER INSERT ON account_activations
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_activation_count_snapshots();
            CREATE CONSTRAINT TRIGGER activation_count_snapshot_evidence AFTER INSERT ON activation_count_snapshots
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_activation_count_snapshots();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Activation eligibility is immutable; use a forward migration.');
    }
};
