<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE kyc_applications ADD COLUMN identity_number_source varchar(16);
            ALTER TABLE kyc_applications ADD CONSTRAINT kyc_identity_number_source_check CHECK (
                identity_number_source IS NULL OR identity_number_source = 'OCR'
                OR (identity_number_source = 'ADMIN' AND identity_hash IS NOT NULL AND identity_number_encrypted IS NOT NULL
                    AND review_status = 'APPROVED' AND reviewed_by_admin_user_id IS NOT NULL AND NOT automatically_approved)
            );
            CREATE OR REPLACE FUNCTION protect_kyc_recognized_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.review_status = 'PENDING' AND NEW.review_status = 'APPROVED' AND NEW.document_type = 'NATIONAL_ID'
                    AND (NEW.identity_hash IS NULL OR NEW.identity_number_encrypted IS NULL)
                THEN RAISE EXCEPTION 'New national ID approval requires an identity number'; END IF;
                IF NEW.identity_number_encrypted IS DISTINCT FROM OLD.identity_number_encrypted
                    OR NEW.identity_hash IS DISTINCT FROM OLD.identity_hash
                    OR NEW.identity_number_source IS DISTINCT FROM OLD.identity_number_source THEN
                    IF OLD.identity_number_encrypted IS NOT NULL OR OLD.identity_hash IS NOT NULL OR OLD.identity_number_source IS NOT NULL
                        OR OLD.processing_status IS NULL OR OLD.review_status <> 'PENDING'
                        OR NEW.identity_number_encrypted IS NULL OR NEW.identity_hash IS NULL
                        OR NOT (
                            (NEW.identity_number_source IS DISTINCT FROM 'ADMIN' AND NEW.ocr_status = 'SUCCEEDED' AND NEW.ocr_result_encrypted IS NOT NULL)
                            OR (NEW.identity_number_source IS NOT DISTINCT FROM 'ADMIN' AND NEW.review_status = 'APPROVED'
                                AND NEW.reviewed_by_admin_user_id IS NOT NULL AND NOT NEW.automatically_approved)
                        )
                    THEN RAISE EXCEPTION 'KYC identity may only be filled once by recognition or administrator approval'; END IF;
                END IF;
                RETURN NEW;
            END; $$;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: administrator identity provenance must be retained.');
    }
};
