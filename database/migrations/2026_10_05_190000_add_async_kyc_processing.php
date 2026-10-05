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
            $table->string('processing_status', 32)->nullable();
            $table->unsignedInteger('processing_generation')->default(0);
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->string('processing_error', 64)->nullable();
            $table->timestampTz('next_processing_at')->nullable()->index();
            $table->uuid('submission_key')->nullable();
            $table->uuid('submission_request_id')->nullable();
            $table->char('submission_fingerprint', 64)->nullable();
            $table->unique(['tenant_id', 'user_id', 'submission_key'], 'kyc_submission_dedup');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE kyc_applications ALTER COLUMN identity_number_encrypted DROP NOT NULL;
            ALTER TABLE kyc_applications ALTER COLUMN identity_hash DROP NOT NULL;
            ALTER TABLE kyc_applications ADD CONSTRAINT kyc_pending_identity_check CHECK (
                (identity_number_encrypted IS NOT NULL AND identity_hash IS NOT NULL)
                OR (identity_number_encrypted IS NULL AND identity_hash IS NULL AND ocr_status <> 'SUCCEEDED' AND review_status <> 'APPROVED' AND processing_status IS NOT NULL)
            );
            CREATE OR REPLACE FUNCTION protect_kyc_recognized_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.identity_number_encrypted IS DISTINCT FROM OLD.identity_number_encrypted OR NEW.identity_hash IS DISTINCT FROM OLD.identity_hash THEN
                    IF OLD.identity_number_encrypted IS NOT NULL OR OLD.identity_hash IS NOT NULL
                       OR OLD.processing_status IS NULL OR OLD.review_status <> 'PENDING'
                       OR NEW.ocr_status <> 'SUCCEEDED' OR NEW.ocr_result_encrypted IS NULL
                       OR NEW.identity_number_encrypted IS NULL OR NEW.identity_hash IS NULL
                    THEN RAISE EXCEPTION 'KYC identity may only be filled by initial successful recognition'; END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER kyc_recognized_identity_immutable BEFORE UPDATE ON kyc_applications
                FOR EACH ROW EXECUTE FUNCTION protect_kyc_recognized_identity();
            SQL);
        Schema::create('kyc_processing_retries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('application_id')->constrained('kyc_applications')->restrictOnDelete();
            $table->foreignUuid('result_application_id')->constrained('kyc_applications')->restrictOnDelete();
            $table->foreignUuid('admin_user_id')->constrained('admin_users')->restrictOnDelete();
            $table->text('reason');
            $table->unsignedInteger('generation');
            $table->timestampTz('created_at');
        });
        $permission = DB::table('permissions')->where('name', 'kyc.review')->value('id');
        if ($permission) {
            foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $role) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: retained KYC submissions must not be removed.');
    }
};
