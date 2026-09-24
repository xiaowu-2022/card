<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE kyc_applications DROP CONSTRAINT kyc_document_type_check');
        DB::statement("ALTER TABLE kyc_applications ADD CONSTRAINT kyc_document_type_check CHECK (document_type IN ('NATIONAL_ID','PASSPORT'))");
        DB::statement('ALTER TABLE identity_records DROP CONSTRAINT identity_document_type_check');
        DB::statement("ALTER TABLE identity_records ADD CONSTRAINT identity_document_type_check CHECK (document_type IN ('NATIONAL_ID','PASSPORT'))");
        DB::statement('ALTER TABLE kyc_applications ALTER COLUMN back_object_key DROP NOT NULL');
    }

    public function down(): void
    {
        throw new RuntimeException('Passport records require forward migration.');
    }
};
