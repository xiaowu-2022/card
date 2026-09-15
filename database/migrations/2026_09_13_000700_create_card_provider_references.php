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
        Schema::create('platform_card_provider_references', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->decimal('reference_balance', 20, 8);
            $table->string('asset_code', 8)->default('USDT');
            $table->string('creation_hash', 64);
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('created_by')->constrained('admin_users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->constrained('admin_users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE platform_card_provider_references ADD CONSTRAINT card_provider_reference_values CHECK (reference_balance >= 0 AND reference_balance = trunc(reference_balance, 2) AND asset_code = 'USDT' AND length(trim(name)) > 0 AND version > 0)");
        $id = (string) Str::uuid();
        DB::table('permissions')->insert(['id' => $id, 'name' => 'card_provider_reference.manage', 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $role) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        throw new LogicException('Preserve reference records and their audit provenance; use a forward migration.');
    }
};
