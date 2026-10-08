<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE kyc_applications DROP CONSTRAINT kyc_pending_identity_check;
            ALTER TABLE kyc_applications ADD CONSTRAINT kyc_pending_identity_check CHECK (
                (identity_number_encrypted IS NOT NULL AND identity_hash IS NOT NULL)
                OR (identity_number_encrypted IS NULL AND identity_hash IS NULL AND ocr_status <> 'SUCCEEDED'
                    AND processing_status IS NOT NULL AND (review_status <> 'APPROVED'
                        OR (reviewed_by_admin_user_id IS NOT NULL AND NOT automatically_approved)))
            );
            ALTER TABLE identity_records ADD COLUMN verification_basis varchar(16) NOT NULL DEFAULT 'OCR';
            ALTER TABLE identity_records ALTER COLUMN identity_number_encrypted DROP NOT NULL;
            ALTER TABLE identity_records ALTER COLUMN identity_hash DROP NOT NULL;
            ALTER TABLE identity_records ADD CONSTRAINT identity_verification_basis_check CHECK (verification_basis IN ('OCR', 'MANUAL'));
            ALTER TABLE identity_records ADD CONSTRAINT identity_number_presence_check CHECK (
                (identity_number_encrypted IS NOT NULL AND identity_hash IS NOT NULL)
                OR (identity_number_encrypted IS NULL AND identity_hash IS NULL AND verification_basis = 'MANUAL')
            );
            CREATE OR REPLACE FUNCTION check_manual_identity_source() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.verification_basis = 'MANUAL' AND NOT EXISTS (
                    SELECT 1 FROM kyc_applications a WHERE a.id = NEW.source_kyc_application_id
                        AND a.tenant_id = NEW.tenant_id AND a.user_id = NEW.user_id
                        AND a.review_status = 'APPROVED' AND a.reviewed_by_admin_user_id IS NOT NULL
                        AND NOT a.automatically_approved
                        AND a.identity_hash IS NOT DISTINCT FROM NEW.identity_hash
                        AND a.identity_number_encrypted IS NOT DISTINCT FROM NEW.identity_number_encrypted
                ) THEN RAISE EXCEPTION 'Manual identity requires matching administrator approval'; END IF;
                RETURN NEW;
            END; $$;
            CREATE CONSTRAINT TRIGGER manual_identity_source_check AFTER INSERT OR UPDATE ON identity_records
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_manual_identity_source();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: manual verification history must be retained.');
    }
};
