<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_applications', function (Blueprint $table): void {
            $table->text('back_ocr_result_encrypted')->nullable();
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_kyc_back_ocr_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.document_type = 'NATIONAL_ID' AND NEW.review_status = 'APPROVED' AND NEW.automatically_approved
                    AND NEW.back_ocr_result_encrypted IS NULL
                THEN
                    IF TG_OP = 'INSERT' THEN
                        RAISE EXCEPTION 'New automatic national ID approval requires back-side OCR evidence';
                    ELSIF OLD.review_status = 'PENDING' THEN
                        RAISE EXCEPTION 'New automatic national ID approval requires back-side OCR evidence';
                    END IF;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.back_ocr_result_encrypted IS NOT NULL AND (
                        NEW.document_type <> 'NATIONAL_ID' OR NEW.back_object_key IS NULL
                        OR NEW.review_status <> 'PENDING' OR NEW.processing_status IS DISTINCT FROM 'QUEUED'
                        OR NEW.processing_generation < 1 OR NOT EXISTS (
                            SELECT 1 FROM kyc_applications a WHERE a.id = NEW.resubmission_of_id
                                AND a.tenant_id = NEW.tenant_id AND a.user_id = NEW.user_id
                                AND a.review_status = 'REJECTED' AND a.document_type = NEW.document_type
                                AND a.document_country = NEW.document_country AND a.back_object_key = NEW.back_object_key
                                AND a.back_ocr_result_encrypted = NEW.back_ocr_result_encrypted
                        )
                    ) THEN RAISE EXCEPTION 'Back-side OCR evidence may only be copied from a matching rejected application'; END IF;
                    RETURN NEW;
                END IF;

                IF OLD.back_ocr_result_encrypted IS NOT NULL AND (
                    NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.user_id IS DISTINCT FROM OLD.user_id
                    OR NEW.document_type IS DISTINCT FROM OLD.document_type OR NEW.back_object_key IS DISTINCT FROM OLD.back_object_key
                ) THEN RAISE EXCEPTION 'Back-side OCR evidence binding is immutable'; END IF;
                IF NEW.back_ocr_result_encrypted IS DISTINCT FROM OLD.back_ocr_result_encrypted THEN
                    IF OLD.back_ocr_result_encrypted IS NOT NULL OR NEW.back_ocr_result_encrypted IS NULL
                        OR NEW.back_ocr_result_encrypted = '' OR OLD.review_status <> 'PENDING' OR NEW.review_status <> 'PENDING'
                        OR NEW.document_type <> 'NATIONAL_ID' OR NEW.back_object_key IS NULL
                        OR OLD.processing_status IS DISTINCT FROM 'PROCESSING' OR NEW.processing_status IS DISTINCT FROM 'PROCESSING'
                        OR OLD.processing_generation < 1 OR NEW.processing_generation IS DISTINCT FROM OLD.processing_generation
                    THEN RAISE EXCEPTION 'Back-side OCR evidence may only be filled once during pending processing'; END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER kyc_back_ocr_evidence_immutable BEFORE INSERT OR UPDATE ON kyc_applications
                FOR EACH ROW EXECUTE FUNCTION protect_kyc_back_ocr_evidence();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: retained back-side KYC recognition evidence must not be removed.');
    }
};
