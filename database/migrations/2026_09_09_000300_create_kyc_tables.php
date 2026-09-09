<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenant_kyc_settings')->whereNull('max_accounts_per_identity')->update(['max_accounts_per_identity' => 1]);
        DB::statement('ALTER TABLE tenant_kyc_settings ALTER COLUMN max_accounts_per_identity SET DEFAULT 1');
        DB::statement('ALTER TABLE tenant_kyc_settings ALTER COLUMN max_accounts_per_identity SET NOT NULL');
        DB::statement('ALTER TABLE tenant_kyc_settings ADD CONSTRAINT tenant_kyc_max_accounts_check CHECK (max_accounts_per_identity >= 1)');

        Schema::create('kyc_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('user_id');
            $table->uuid('resubmission_of_id')->nullable();
            $table->string('document_type', 32);
            $table->string('document_country', 2);
            $table->text('identity_number_encrypted');
            $table->char('identity_hash', 64);
            $table->string('front_object_key');
            $table->string('back_object_key');
            $table->string('ocr_status', 24);
            $table->string('ocr_provider', 64)->nullable();
            $table->string('ocr_reference', 255)->nullable();
            $table->text('ocr_result_encrypted')->nullable();
            $table->string('review_status', 32);
            $table->string('review_reason_code', 32)->nullable();
            $table->string('review_message', 500)->nullable();
            $table->foreignUuid('reviewed_by_admin_user_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('submitted_at');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'tenant_id', 'user_id']);
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['resubmission_of_id', 'tenant_id', 'user_id'], 'kyc_resubmission_same_user_fk')
                ->references(['id', 'tenant_id', 'user_id'])->on('kyc_applications')->restrictOnDelete();
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'review_status']);
            $table->index(['tenant_id', 'submitted_at']);
            $table->index(['tenant_id', 'identity_hash']);
        });

        Schema::create('identity_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('user_id');
            $table->uuid('source_kyc_application_id');
            $table->string('document_type', 32);
            $table->string('document_country', 2);
            $table->text('identity_number_encrypted');
            $table->char('identity_hash', 64);
            $table->timestampTz('verified_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id']);
            $table->foreign(['user_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('users')->restrictOnDelete();
            $table->foreign(['source_kyc_application_id', 'tenant_id', 'user_id'], 'identity_source_same_user_fk')
                ->references(['id', 'tenant_id', 'user_id'])->on('kyc_applications')->restrictOnDelete();
            $table->index(['tenant_id', 'identity_hash']);
        });

        DB::statement("ALTER TABLE kyc_applications ADD CONSTRAINT kyc_document_type_check CHECK (document_type IN ('NATIONAL_ID'))");
        DB::statement("ALTER TABLE kyc_applications ADD CONSTRAINT kyc_country_check CHECK (document_country ~ '^[A-Z]{2}$')");
        DB::statement("ALTER TABLE kyc_applications ADD CONSTRAINT kyc_identity_hash_check CHECK (identity_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE kyc_applications ADD CONSTRAINT kyc_ocr_status_check CHECK (ocr_status IN ('NOT_STARTED','PROCESSING','SUCCEEDED','FAILED'))");
        DB::statement("ALTER TABLE kyc_applications ADD CONSTRAINT kyc_review_status_check CHECK (review_status IN ('PENDING','APPROVED','REJECTED','RESUBMISSION_REQUIRED'))");
        DB::statement("ALTER TABLE kyc_applications ADD CONSTRAINT kyc_review_fields_check CHECK ((review_status = 'PENDING' AND reviewed_at IS NULL AND reviewed_by_admin_user_id IS NULL AND review_reason_code IS NULL AND review_message IS NULL) OR (review_status = 'APPROVED' AND reviewed_at IS NOT NULL AND reviewed_by_admin_user_id IS NOT NULL AND review_reason_code IS NULL AND review_message IS NULL) OR (review_status IN ('REJECTED','RESUBMISSION_REQUIRED') AND reviewed_at IS NOT NULL AND reviewed_by_admin_user_id IS NOT NULL AND review_reason_code IS NOT NULL AND review_message IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX kyc_one_pending_application_per_user ON kyc_applications (tenant_id, user_id) WHERE review_status = 'PENDING'");
        DB::statement("ALTER TABLE identity_records ADD CONSTRAINT identity_document_type_check CHECK (document_type IN ('NATIONAL_ID'))");
        DB::statement("ALTER TABLE identity_records ADD CONSTRAINT identity_country_check CHECK (document_country ~ '^[A-Z]{2}$')");
        DB::statement("ALTER TABLE identity_records ADD CONSTRAINT identity_hash_check CHECK (identity_hash ~ '^[a-f0-9]{64}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_records');
        Schema::dropIfExists('kyc_applications');
        DB::statement('ALTER TABLE tenant_kyc_settings DROP CONSTRAINT IF EXISTS tenant_kyc_max_accounts_check');
        DB::statement('ALTER TABLE tenant_kyc_settings ALTER COLUMN max_accounts_per_identity DROP NOT NULL');
        DB::statement('ALTER TABLE tenant_kyc_settings ALTER COLUMN max_accounts_per_identity DROP DEFAULT');
    }
};
