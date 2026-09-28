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
        Schema::create('oss_configurations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('region');
            $t->string('bucket');
            $t->string('endpoint');
            $t->string('public_url');
            $t->text('credentials');
            $t->timestampTz('verified_at')->nullable();
            $t->uuid('created_by');
            $t->timestampsTz();
        });
        Schema::create('media_storage_settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->uuid('active_configuration_id')->nullable()->references('id')->on('oss_configurations');
            $t->timestampsTz();
        });
        DB::table('media_storage_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('stored_images', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->references('id')->on('tenants');
            $t->string('source_disk', 64);
            $t->string('source_key', 512);
            $t->string('purpose', 32);
            $t->string('business_reference', 255)->nullable();
            $t->uuid('configuration_id')->nullable()->references('id')->on('oss_configurations');
            $t->string('object_key', 512);
            $t->string('codec', 32)->default('plain');
            $t->string('mime', 100);
            $t->unsignedBigInteger('size');
            $t->string('sha256', 64);
            $t->uuid('migration_configuration_id')->nullable()->references('id')->on('oss_configurations');
            $t->string('migration_object_key', 512)->nullable();
            $t->string('state', 32)->default('uploading');
            $t->timestampTz('cleanup_after')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->string('last_error', 64)->nullable();
            $t->timestampsTz();
            $t->unique(['source_disk', 'source_key']);
            $t->index(['state', 'cleanup_after']);
            $t->index(['tenant_id', 'purpose']);
        });
        $id = DB::table('permissions')->where('name', 'storage.manage')->value('id') ?? (string) Str::uuid();
        DB::table('permissions')->insertOrIgnore(['id' => $id, 'name' => 'storage.manage', 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('roles')->where('scope_type', 'PLATFORM')->whereIn('name', ['PLATFORM_OWNER', 'PLATFORM_ADMIN'])->pluck('id') as $role) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_images');
        Schema::dropIfExists('media_storage_settings');
        Schema::dropIfExists('oss_configurations');
    }
};
