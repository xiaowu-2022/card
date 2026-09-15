<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedInteger('rank');
            $table->string('name', 80);
            $table->decimal('reward_amount', 20, 8);
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'rank']);
            $table->unique(['id', 'tenant_id']);
        });
        Schema::create('promotion_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('user_id');
            $table->uuid('inviter_id')->nullable();
            $table->uuid('level_id')->nullable();
            $table->string('invitation_code', 24);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'invitation_code']);
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'inviter_id', 'created_at']);
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['inviter_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('promotion_members')->restrictOnDelete();
            $table->foreign(['level_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('promotion_levels')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE promotion_levels ADD CONSTRAINT promotion_level_valid CHECK
                (rank BETWEEN 1 AND 1000000 AND revision > 0 AND reward_amount >= 0 AND reward_amount = trunc(reward_amount));
            ALTER TABLE promotion_members ADD CONSTRAINT promotion_invitation_valid CHECK
                (invitation_code ~ '^[A-F0-9]{24}$' AND inviter_id IS DISTINCT FROM id);

            CREATE OR REPLACE FUNCTION protect_promotion_foundation() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Promotion history cannot be deleted';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                        RAISE EXCEPTION 'Promotion scope is immutable';
                    END IF;
                    IF TG_TABLE_NAME = 'promotion_members' THEN
                        IF NEW.user_id IS DISTINCT FROM OLD.user_id OR NEW.inviter_id IS DISTINCT FROM OLD.inviter_id OR NEW.invitation_code IS DISTINCT FROM OLD.invitation_code THEN
                            RAISE EXCEPTION 'Invitation relationships are immutable';
                        END IF;
                    ELSE
                        IF NEW.rank IS DISTINCT FROM OLD.rank OR NEW.revision <> OLD.revision + 1 THEN
                            RAISE EXCEPTION 'Promotion rank is immutable and every edit requires a new revision';
                        END IF;
                    END IF;
                END IF;
                IF TG_TABLE_NAME = 'promotion_members' AND TG_OP = 'INSERT' THEN
                  IF NEW.inviter_id IS NOT NULL THEN
                    IF EXISTS (
                        WITH RECURSIVE chain AS (
                            SELECT id, inviter_id FROM promotion_members WHERE tenant_id = NEW.tenant_id AND id = NEW.inviter_id
                            UNION
                            SELECT p.id, p.inviter_id FROM promotion_members p JOIN chain c ON p.id = c.inviter_id WHERE p.tenant_id = NEW.tenant_id
                        ) SELECT 1 FROM chain WHERE id = NEW.id OR inviter_id = NEW.id
                    ) THEN
                        RAISE EXCEPTION 'Invitation cycles are forbidden';
                    END IF;
                  END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER protect_promotion_levels BEFORE UPDATE OR DELETE ON promotion_levels
                FOR EACH ROW EXECUTE FUNCTION protect_promotion_foundation();
            CREATE TRIGGER protect_promotion_members BEFORE INSERT OR UPDATE OR DELETE ON promotion_members
                FOR EACH ROW EXECUTE FUNCTION protect_promotion_foundation();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_members');
        Schema::dropIfExists('promotion_levels');
        DB::statement('DROP FUNCTION IF EXISTS protect_promotion_foundation()');
    }
};
