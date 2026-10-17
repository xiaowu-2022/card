<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE company_invitation_changes (
                id uuid PRIMARY KEY,
                tenant_id uuid NOT NULL REFERENCES tenants(id),
                company_invitation_id uuid NOT NULL,
                old_code varchar(6) NOT NULL REFERENCES promotion_invitation_reservations(code),
                new_code varchar(6) NOT NULL REFERENCES promotion_invitation_reservations(code),
                old_enabled boolean NOT NULL,
                enabled boolean NOT NULL,
                revision bigint NOT NULL CHECK (revision > 0),
                actor_id uuid NOT NULL REFERENCES admin_users(id),
                actor_name varchar(255) NOT NULL,
                request_id uuid NOT NULL,
                reason varchar(500) NOT NULL CHECK (length(trim(reason)) > 0),
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, request_id),
                UNIQUE (company_invitation_id, revision),
                FOREIGN KEY (company_invitation_id, tenant_id) REFERENCES promotion_company_invitations(id, tenant_id),
                CHECK (old_code <> new_code OR old_enabled <> enabled)
            );
            CREATE TRIGGER company_invitation_changes_immutable BEFORE UPDATE OR DELETE ON company_invitation_changes
                FOR EACH ROW EXECUTE FUNCTION protect_invitation_evidence();
            CREATE OR REPLACE FUNCTION validate_company_invitation_change() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE current_code varchar(6); current_enabled boolean := true; current_revision bigint := 0;
            BEGIN
                -- Same lock as registration challenge acceptance and administrative changes.
                PERFORM 1 FROM tenants WHERE id=NEW.tenant_id FOR UPDATE;
                SELECT invitation_code INTO current_code FROM promotion_company_invitations
                    WHERE id=NEW.company_invitation_id AND tenant_id=NEW.tenant_id;
                IF current_code IS NULL THEN RAISE EXCEPTION 'Unknown company invitation'; END IF;
                IF EXISTS (SELECT 1 FROM company_invitation_changes WHERE company_invitation_id=NEW.company_invitation_id) THEN
                    SELECT new_code, enabled, revision INTO current_code, current_enabled, current_revision
                        FROM company_invitation_changes WHERE company_invitation_id=NEW.company_invitation_id
                        ORDER BY revision DESC LIMIT 1;
                END IF;
                IF NEW.old_code<>current_code OR NEW.old_enabled<>current_enabled OR NEW.revision<>current_revision+1 THEN
                    RAISE EXCEPTION 'Company invitation change is stale';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM promotion_invitation_reservations r WHERE r.code=NEW.new_code
                    AND r.tenant_id=NEW.tenant_id AND r.company_invitation_id=NEW.company_invitation_id
                    AND (NEW.new_code=NEW.old_code OR r.revision=NEW.revision)) THEN
                    RAISE EXCEPTION 'Company invitation requires its reserved code';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER validate_company_invitation_change BEFORE INSERT ON company_invitation_changes
                FOR EACH ROW EXECUTE FUNCTION validate_company_invitation_change();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Company invitation history and reserved codes must be retained.');
    }
};
