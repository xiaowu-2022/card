<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL runs this entire migration in one transaction. Block writers
        // before the one-time remap; no runtime immutability bypass is retained.
        DB::unprepared(<<<'SQL'
            LOCK TABLE tenants, users, promotion_company_invitations, promotion_members IN ACCESS EXCLUSIVE MODE;
            CREATE TEMP TABLE promotion_code_remap ON COMMIT DROP AS
            SELECT source.*, (523611 + row_number() OVER (ORDER BY created_at, kind, id))::text AS new_code
            FROM (
                SELECT 'company' AS kind, id, tenant_id, NULL::uuid AS user_id, invitation_code AS old_code, created_at
                    FROM promotion_company_invitations
                UNION ALL
                SELECT 'member', id, tenant_id, user_id, invitation_code, created_at FROM promotion_members
                UNION ALL
                SELECT 'company', gen_random_uuid(), t.id, NULL::uuid, NULL, t.created_at FROM tenants t
                    WHERE NOT EXISTS (SELECT 1 FROM promotion_company_invitations c WHERE c.tenant_id = t.id)
                UNION ALL
                SELECT 'member', gen_random_uuid(), u.tenant_id, u.id, NULL, u.created_at FROM users u
                    WHERE NOT EXISTS (SELECT 1 FROM promotion_members m WHERE m.tenant_id = u.tenant_id AND m.user_id = u.id)
            ) source;
            DO $$ BEGIN
                IF (SELECT count(*) FROM promotion_code_remap) > 476388 THEN
                    RAISE EXCEPTION 'PROMOTION_INVITATION_EXHAUSTED';
                END IF;
            END $$;

            CREATE TABLE promotion_invitation_aliases (
                tenant_id uuid NOT NULL REFERENCES tenants(id),
                old_code varchar(24) NOT NULL CHECK (old_code ~ '^[A-F0-9]{24}$'),
                member_id uuid,
                company_invitation_id uuid,
                PRIMARY KEY (tenant_id, old_code),
                CHECK ((member_id IS NOT NULL)::integer + (company_invitation_id IS NOT NULL)::integer = 1),
                FOREIGN KEY (member_id, tenant_id) REFERENCES promotion_members(id, tenant_id),
                FOREIGN KEY (company_invitation_id, tenant_id) REFERENCES promotion_company_invitations(id, tenant_id)
            );
            INSERT INTO promotion_invitation_aliases (tenant_id, old_code, member_id, company_invitation_id)
                SELECT tenant_id, old_code, CASE WHEN kind = 'member' THEN id END, CASE WHEN kind = 'company' THEN id END
                FROM promotion_code_remap WHERE old_code IS NOT NULL;
            CREATE TRIGGER promotion_alias_immutable BEFORE UPDATE OR DELETE ON promotion_invitation_aliases
                FOR EACH ROW EXECUTE FUNCTION reject_ledger_history_mutation();

            ALTER TABLE promotion_members DISABLE TRIGGER protect_promotion_members;
            ALTER TABLE promotion_company_invitations DISABLE TRIGGER company_invitation_immutable;
            ALTER TABLE promotion_members DROP CONSTRAINT promotion_invitation_valid;
            ALTER TABLE promotion_company_invitations DROP CONSTRAINT company_invitation_code_format;
            UPDATE promotion_members m SET invitation_code = r.new_code
                FROM promotion_code_remap r WHERE r.kind = 'member' AND m.id = r.id AND m.tenant_id = r.tenant_id;
            UPDATE promotion_company_invitations c SET invitation_code = r.new_code
                FROM promotion_code_remap r WHERE r.kind = 'company' AND c.id = r.id AND c.tenant_id = r.tenant_id;
            INSERT INTO promotion_company_invitations (id, tenant_id, invitation_code, created_at, updated_at)
                SELECT id, tenant_id, new_code, created_at, created_at FROM promotion_code_remap WHERE kind = 'company' AND old_code IS NULL;
            INSERT INTO promotion_members (id, tenant_id, user_id, invitation_code, created_at, updated_at)
                SELECT id, tenant_id, user_id, new_code, created_at, created_at FROM promotion_code_remap WHERE kind = 'member' AND old_code IS NULL;
            ALTER TABLE promotion_members ENABLE TRIGGER protect_promotion_members;
            ALTER TABLE promotion_company_invitations ENABLE TRIGGER company_invitation_immutable;
            ALTER TABLE promotion_members ALTER COLUMN invitation_code TYPE varchar(6);
            ALTER TABLE promotion_company_invitations ALTER COLUMN invitation_code TYPE varchar(6);
            ALTER TABLE promotion_members ADD CONSTRAINT promotion_invitation_valid
                CHECK (invitation_code ~ '^[0-9]{6}$' AND invitation_code >= '523612' AND (inviter_id IS NULL OR inviter_id <> id));
            ALTER TABLE promotion_company_invitations ADD CONSTRAINT company_invitation_code_format
                CHECK (invitation_code ~ '^[0-9]{6}$' AND invitation_code >= '523612');

            CREATE TABLE promotion_invitation_counter (
                id smallint PRIMARY KEY CHECK (id = 1),
                next_value integer NOT NULL CHECK (next_value BETWEEN 523612 AND 1000000)
            );
            INSERT INTO promotion_invitation_counter SELECT 1, 523612 + count(*) FROM promotion_code_remap;
            CREATE OR REPLACE FUNCTION protect_promotion_invitation_counter() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'Invitation counter cannot be replaced'; END IF;
                IF NEW.id <> OLD.id OR NEW.next_value <> OLD.next_value + 1 THEN
                    RAISE EXCEPTION 'Invitation counter must advance exactly once';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER protect_promotion_invitation_counter BEFORE INSERT OR UPDATE OR DELETE ON promotion_invitation_counter
                FOR EACH ROW EXECUTE FUNCTION protect_promotion_invitation_counter();
            CREATE OR REPLACE FUNCTION assign_promotion_invitation_code() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE allocated integer;
            BEGIN
                IF NEW.invitation_code IS NOT NULL THEN RAISE EXCEPTION 'Invitation codes are server allocated'; END IF;
                UPDATE promotion_invitation_counter SET next_value = next_value + 1
                    WHERE id = 1 AND next_value <= 999999 RETURNING next_value - 1 INTO allocated;
                IF allocated IS NULL THEN RAISE EXCEPTION 'PROMOTION_INVITATION_EXHAUSTED'; END IF;
                NEW.invitation_code := allocated::text;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER assign_promotion_invitation_code BEFORE INSERT ON promotion_members
                FOR EACH ROW EXECUTE FUNCTION assign_promotion_invitation_code();
            CREATE TRIGGER assign_promotion_invitation_code BEFORE INSERT ON promotion_company_invitations
                FOR EACH ROW EXECUTE FUNCTION assign_promotion_invitation_code();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Invitation-code migration is forward-only; preserve referral identities and legacy links.');
    }
};
