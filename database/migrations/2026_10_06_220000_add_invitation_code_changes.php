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
        DB::statement('LOCK TABLE promotion_members, promotion_company_invitations, promotion_invitation_counter IN ACCESS EXCLUSIVE MODE');
        Schema::table('promotion_members', fn (Blueprint $t) => $t->unsignedBigInteger('invitation_revision')->default(0));
        DB::unprepared(<<<'SQL'
            CREATE TABLE promotion_invitation_reservations (
                code varchar(6) PRIMARY KEY CHECK (code ~ '^[0-9]{6}$' AND code >= '523612'),
                tenant_id uuid NOT NULL REFERENCES tenants(id),
                member_id uuid,
                revision bigint NOT NULL DEFAULT 0 CHECK (revision >= 0),
                company_invitation_id uuid,
                created_at timestamptz NOT NULL DEFAULT now(),
                CHECK ((member_id IS NULL) <> (company_invitation_id IS NULL)),
                FOREIGN KEY (member_id, tenant_id) REFERENCES promotion_members(id, tenant_id) DEFERRABLE INITIALLY DEFERRED,
                FOREIGN KEY (company_invitation_id, tenant_id) REFERENCES promotion_company_invitations(id, tenant_id) DEFERRABLE INITIALLY DEFERRED
            );
            -- A duplicate aborts this migration; never renumber existing identities.
            INSERT INTO promotion_invitation_reservations(code, tenant_id, member_id, company_invitation_id)
                SELECT invitation_code, tenant_id, id, NULL FROM promotion_members
                UNION ALL SELECT invitation_code, tenant_id, NULL, id FROM promotion_company_invitations;
            CREATE OR REPLACE FUNCTION protect_invitation_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'Invitation evidence is immutable'; END $$;
            CREATE TRIGGER invitation_reservations_immutable BEFORE UPDATE OR DELETE ON promotion_invitation_reservations
                FOR EACH ROW EXECUTE FUNCTION protect_invitation_evidence();
            CREATE OR REPLACE FUNCTION assign_promotion_invitation_code() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE allocated integer;
            BEGIN
                IF NEW.invitation_code IS NOT NULL THEN RAISE EXCEPTION 'Invitation codes are server allocated'; END IF;
                LOOP
                    UPDATE promotion_invitation_counter SET next_value=next_value+1
                        WHERE id=1 AND next_value<=999999 RETURNING next_value-1 INTO allocated;
                    IF allocated IS NULL THEN RAISE EXCEPTION 'PROMOTION_INVITATION_EXHAUSTED'; END IF;
                    EXIT WHEN NOT EXISTS (SELECT 1 FROM promotion_invitation_reservations WHERE code=allocated::text);
                END LOOP;
                NEW.invitation_code := allocated::text;
                INSERT INTO promotion_invitation_reservations(code, tenant_id, member_id, company_invitation_id)
                    VALUES (NEW.invitation_code, NEW.tenant_id,
                        CASE WHEN TG_TABLE_NAME='promotion_members' THEN NEW.id END,
                        CASE WHEN TG_TABLE_NAME='promotion_company_invitations' THEN NEW.id END);
                RETURN NEW;
            END $$;
            SQL);
        Schema::create('promotion_invitation_changes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->uuid('user_id');
            $t->uuid('member_id');
            $t->string('old_code', 6);
            $t->string('new_code', 6)->unique();
            $t->unsignedBigInteger('revision');
            $t->foreignUuid('actor_id')->constrained('admin_users');
            $t->string('actor_name');
            $t->uuid('request_id');
            $t->string('reason', 500);
            $t->bigInteger('transaction_id')->default(DB::raw('txid_current()'));
            $t->timestampTz('created_at', 6);
            $t->unique(['tenant_id', 'user_id', 'request_id']);
            $t->unique(['member_id', 'revision']);
            $t->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users');
            $t->foreign(['member_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('promotion_members');
            $t->foreign('old_code')->references('code')->on('promotion_invitation_reservations');
            $t->foreign('new_code')->references('code')->on('promotion_invitation_reservations');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE promotion_invitation_changes ADD CHECK (revision>0 AND old_code<>new_code AND length(trim(reason))>0);
            CREATE TRIGGER invitation_changes_immutable BEFORE UPDATE OR DELETE ON promotion_invitation_changes
                FOR EACH ROW EXECUTE FUNCTION protect_invitation_evidence();
            CREATE OR REPLACE FUNCTION validate_invitation_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.transaction_id<>txid_current() OR NOT EXISTS (
                    SELECT 1 FROM promotion_members m WHERE m.id=NEW.member_id AND m.tenant_id=NEW.tenant_id
                        AND m.user_id=NEW.user_id AND m.invitation_code=NEW.old_code AND m.invitation_revision+1=NEW.revision
                ) OR NOT EXISTS (
                    SELECT 1 FROM promotion_invitation_reservations r WHERE r.code=NEW.new_code
                        AND r.member_id=NEW.member_id AND r.tenant_id=NEW.tenant_id AND r.revision=NEW.revision
                ) THEN RAISE EXCEPTION 'Invitation change evidence does not match current membership'; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER validate_invitation_change BEFORE INSERT ON promotion_invitation_changes
                FOR EACH ROW EXECUTE FUNCTION validate_invitation_change();
            CREATE OR REPLACE FUNCTION verify_invitation_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM promotion_members m WHERE m.id=NEW.member_id AND m.tenant_id=NEW.tenant_id
                    AND m.user_id=NEW.user_id AND m.invitation_revision>=NEW.revision)
                OR (SELECT count(*) FROM promotion_invitation_reservations r WHERE r.code IN (NEW.old_code, NEW.new_code)
                    AND r.member_id=NEW.member_id AND r.tenant_id=NEW.tenant_id)<>2 THEN
                    RAISE EXCEPTION 'Invitation change was not applied';
                END IF;
                RETURN NEW;
            END $$;
            CREATE CONSTRAINT TRIGGER invitation_change_evidence AFTER INSERT ON promotion_invitation_changes
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION verify_invitation_change();
            CREATE OR REPLACE FUNCTION guard_invitation_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.invitation_code IS DISTINCT FROM OLD.invitation_code THEN
                    IF NEW.invitation_revision<>OLD.invitation_revision+1 OR NOT EXISTS (
                        SELECT 1 FROM promotion_invitation_changes c
                        JOIN promotion_invitation_reservations r ON r.code=c.new_code AND r.member_id=c.member_id AND r.tenant_id=c.tenant_id AND r.revision=c.revision
                        WHERE c.member_id=NEW.id AND c.tenant_id=NEW.tenant_id AND c.user_id=NEW.user_id
                            AND c.old_code=OLD.invitation_code AND c.new_code=NEW.invitation_code
                            AND c.revision=NEW.invitation_revision AND c.transaction_id=txid_current()
                    ) THEN RAISE EXCEPTION 'Invitation change requires matching evidence'; END IF;
                ELSIF NEW.invitation_revision IS DISTINCT FROM OLD.invitation_revision THEN
                    RAISE EXCEPTION 'Invitation revision requires a code change';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER guard_invitation_change BEFORE UPDATE ON promotion_members
                FOR EACH ROW EXECUTE FUNCTION guard_invitation_change();
            SQL);
        // Replace only the code prohibition; retain scope, relationship and rank guards.
        $definition = DB::selectOne("SELECT pg_get_functiondef('protect_promotion_foundation()'::regprocedure) AS definition")->definition;
        $needle = 'OR NEW.invitation_code IS DISTINCT FROM OLD.invitation_code';
        if (substr_count($definition, $needle) !== 1) {
            throw new RuntimeException('Unexpected promotion foundation guard; invitation migration aborted.');
        }
        DB::unprepared(str_replace($needle, '', $definition));
        $permission = (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore(['id' => $permission, 'name' => 'users.invitation.manage', 'created_at' => now(), 'updated_at' => now()]);
        $permission = DB::table('permissions')->where('name', 'users.invitation.manage')->value('id');
        foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $role) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Invitation reservations and change evidence must be retained.');
    }
};
