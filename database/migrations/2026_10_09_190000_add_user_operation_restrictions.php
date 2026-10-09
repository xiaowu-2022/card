<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('withdrawal_blocked')->default(false);
            $table->boolean('deposit_refund_blocked')->default(false);
            $table->boolean('card_transfer_blocked')->default(false);
            $table->boolean('wallet_transfer_blocked')->default(false);
            $table->unsignedInteger('operation_restrictions_revision')->default(0);
        });
        DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'name' => 'users.restrictions.manage', 'created_at' => now(), 'updated_at' => now()]);
        $permission = DB::table('permissions')->where('name', 'users.restrictions.manage')->value('id');
        foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $role) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('User operation restrictions must not be silently removed.');
    }
};
