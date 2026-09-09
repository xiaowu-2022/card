<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table): void {
            $table->string('status', 16)->default('ACTIVE')->after('password');
            $table->timestampTz('last_login_at')->nullable()->after('email_verified_at');
            $table->index('status');
        });

        Schema::table('admin_invitations', function (Blueprint $table): void {
            $table->foreignUuid('accepted_by')->nullable()->after('accepted_at')
                ->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable()->after('accepted_by');
        });

        DB::statement("ALTER TABLE admin_users ADD CONSTRAINT admin_users_status_check CHECK (status IN ('ACTIVE','SUSPENDED'))");
        DB::statement('CREATE UNIQUE INDEX admin_users_email_lower_unique ON admin_users (LOWER(email))');
        DB::statement("CREATE UNIQUE INDEX admin_invitations_one_pending_per_tenant_email ON admin_invitations (tenant_id, LOWER(email)) WHERE status = 'PENDING'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS admin_invitations_one_pending_per_tenant_email');
        DB::statement('DROP INDEX IF EXISTS admin_users_email_lower_unique');
        DB::statement('ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS admin_users_status_check');

        Schema::table('admin_invitations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropColumn('cancelled_at');
        });

        Schema::table('admin_users', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'last_login_at']);
        });
    }
};
