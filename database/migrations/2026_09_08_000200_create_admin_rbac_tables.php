<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestampTz('email_verified_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('scope_type', 16);
            $table->string('description')->nullable();
            $table->timestampsTz();
            $table->unique(['id', 'scope_type'], 'roles_id_scope_unique');
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignUuid('permission_id')->constrained('permissions')->restrictOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('admin_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('admin_user_id')->constrained('admin_users')->restrictOnDelete();
            $table->string('scope_type', 16);
            $table->uuid('scope_id')->nullable();
            $table->uuid('role_id');
            $table->string('status', 16)->default('ACTIVE');
            $table->timestampsTz();
            $table->index(['scope_type', 'scope_id', 'status']);
            $table->foreign('scope_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['role_id', 'scope_type'], 'admin_memberships_role_scope_foreign')
                ->references(['id', 'scope_type'])->on('roles')->restrictOnDelete();
        });

        Schema::create('admin_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('email')->index();
            $table->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('status', 16)->default('PENDING')->index();
            $table->foreignUuid('invited_by')->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_scope_check CHECK (scope_type IN ('PLATFORM','TENANT'))");
        DB::statement("ALTER TABLE admin_memberships ADD CONSTRAINT admin_memberships_scope_check CHECK ((scope_type = 'PLATFORM' AND scope_id IS NULL) OR (scope_type = 'TENANT' AND scope_id IS NOT NULL))");
        DB::statement("ALTER TABLE admin_memberships ADD CONSTRAINT admin_memberships_status_check CHECK (status IN ('ACTIVE','SUSPENDED','REVOKED'))");
        DB::statement("ALTER TABLE admin_invitations ADD CONSTRAINT admin_invitations_status_check CHECK (status IN ('PENDING','ACCEPTED','EXPIRED','CANCELLED'))");
        DB::statement("CREATE UNIQUE INDEX admin_memberships_unique_platform ON admin_memberships (admin_user_id) WHERE scope_type = 'PLATFORM'");
        DB::statement("CREATE UNIQUE INDEX admin_memberships_unique_tenant ON admin_memberships (admin_user_id, scope_id) WHERE scope_type = 'TENANT'");
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invitations');
        Schema::dropIfExists('admin_memberships');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('admin_users');
    }
};
