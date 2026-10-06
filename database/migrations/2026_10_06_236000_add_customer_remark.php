<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void { DB::statement('ALTER TABLE users ADD COLUMN support_remark varchar(60), ADD COLUMN support_remark_revision integer NOT NULL DEFAULT 0'); }
    public function down(): void { DB::statement('ALTER TABLE users DROP COLUMN support_remark, DROP COLUMN support_remark_revision'); }
};
