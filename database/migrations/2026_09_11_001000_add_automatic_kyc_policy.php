<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE tenant_kyc_settings DROP CONSTRAINT tenant_kyc_review_mode_check;
            ALTER TABLE tenant_kyc_settings ADD CONSTRAINT tenant_kyc_review_mode_check CHECK (review_mode IN ('MANUAL','AUTOMATIC','PROVIDER_AUTOMATIC'));
            ALTER TABLE kyc_applications ADD COLUMN automatically_approved boolean NOT NULL DEFAULT false;
            ALTER TABLE kyc_applications DROP CONSTRAINT kyc_review_fields_check;
            ALTER TABLE kyc_applications ADD CONSTRAINT kyc_review_fields_check CHECK (
                (review_status = 'PENDING' AND NOT automatically_approved AND reviewed_at IS NULL AND reviewed_by_admin_user_id IS NULL AND review_reason_code IS NULL AND review_message IS NULL)
                OR (review_status = 'APPROVED' AND reviewed_at IS NOT NULL AND review_reason_code IS NULL AND review_message IS NULL AND
                    ((automatically_approved AND reviewed_by_admin_user_id IS NULL) OR (NOT automatically_approved AND reviewed_by_admin_user_id IS NOT NULL)))
                OR (review_status IN ('REJECTED','RESUBMISSION_REQUIRED') AND NOT automatically_approved AND reviewed_at IS NOT NULL AND reviewed_by_admin_user_id IS NOT NULL AND review_reason_code IS NOT NULL AND review_message IS NOT NULL)
            );
            CREATE OR REPLACE FUNCTION protect_kyc_approval_provenance() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.review_status <> 'PENDING' AND (
                    NEW.automatically_approved IS DISTINCT FROM OLD.automatically_approved OR
                    NEW.review_status IS DISTINCT FROM OLD.review_status OR
                    NEW.reviewed_by_admin_user_id IS DISTINCT FROM OLD.reviewed_by_admin_user_id OR
                    NEW.reviewed_at IS DISTINCT FROM OLD.reviewed_at OR
                    NEW.review_reason_code IS DISTINCT FROM OLD.review_reason_code OR
                    NEW.review_message IS DISTINCT FROM OLD.review_message
                ) THEN RAISE EXCEPTION 'Completed KYC review is immutable'; END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER kyc_approval_provenance_immutable BEFORE UPDATE ON kyc_applications
                FOR EACH ROW EXECUTE FUNCTION protect_kyc_approval_provenance();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: automatic KYC approval history must be preserved.');
    }
};
