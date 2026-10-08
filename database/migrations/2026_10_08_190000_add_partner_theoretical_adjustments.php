<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE partner_journal_entries DROP CONSTRAINT partner_journal_entries_check');
        DB::statement("ALTER TABLE partner_journal_entries ADD CONSTRAINT partner_journal_entries_check CHECK (kind IN ('REIMBURSEMENT','ADVANCE','ADJUSTMENT_INCREASE','ADJUSTMENT_DECREASE') AND amount>0)");
    }

    public function down(): void
    {
        // Fail closed if adjustment history exists; never delete immutable cooperation evidence.
        DB::statement('ALTER TABLE partner_journal_entries DROP CONSTRAINT partner_journal_entries_check');
        DB::statement("ALTER TABLE partner_journal_entries ADD CONSTRAINT partner_journal_entries_check CHECK (kind IN ('REIMBURSEMENT','ADVANCE') AND amount>0)");
    }
};
