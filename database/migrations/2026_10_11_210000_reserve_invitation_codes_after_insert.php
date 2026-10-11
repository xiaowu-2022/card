<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('LOCK TABLE promotion_members, promotion_company_invitations, promotion_invitation_counter, promotion_invitation_reservations IN ACCESS EXCLUSIVE MODE');
        DB::unprepared(<<<'SQL'
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
                RETURN NEW;
            END $$;
            CREATE OR REPLACE FUNCTION reserve_inserted_promotion_invitation_code() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO promotion_invitation_reservations(code, tenant_id, member_id, company_invitation_id)
                    VALUES (NEW.invitation_code, NEW.tenant_id,
                        CASE WHEN TG_TABLE_NAME='promotion_members' THEN NEW.id END,
                        CASE WHEN TG_TABLE_NAME='promotion_company_invitations' THEN NEW.id END);
                RETURN NEW;
            END $$;
            CREATE TRIGGER reserve_inserted_promotion_invitation_code AFTER INSERT ON promotion_members
                FOR EACH ROW EXECUTE FUNCTION reserve_inserted_promotion_invitation_code();
            CREATE TRIGGER reserve_inserted_promotion_invitation_code AFTER INSERT ON promotion_company_invitations
                FOR EACH ROW EXECUTE FUNCTION reserve_inserted_promotion_invitation_code();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Invitation allocation ordering requires forward migrations.');
    }
};
