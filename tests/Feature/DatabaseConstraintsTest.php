<?php

use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Tenant\Enums\TenantDomainStatus;
use App\Domain\Tenant\Enums\TenantDomainType;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Domain\Tenant\Models\TenantLocale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(fn () => $this->seed());

it('enforces globally unique tenant hostnames', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();

    TenantDomain::query()->create([
        'tenant_id' => $tenant->id,
        'hostname' => 'a.localhost',
        'domain_type' => TenantDomainType::SystemSubdomain,
        'status' => TenantDomainStatus::Active,
    ]);
})->throws(QueryException::class);

it('enforces at most one primary domain per tenant', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();

    TenantDomain::query()->create([
        'tenant_id' => $tenant->id,
        'hostname' => 'alternate-a.localhost',
        'domain_type' => TenantDomainType::SystemSubdomain,
        'status' => TenantDomainStatus::Active,
        'is_primary' => true,
    ]);
})->throws(QueryException::class);

it('enforces at most one default locale per tenant', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();

    TenantLocale::query()->create([
        'tenant_id' => $tenant->id,
        'locale' => 'fr',
        'enabled' => true,
        'is_default' => true,
    ]);
})->throws(QueryException::class);

it('rejects a platform role on a tenant membership', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $role = Role::query()->where('name', 'PLATFORM_OWNER')->firstOrFail();
    $admin = AdminUser::query()->create(['name' => 'Scope Test', 'email' => 'scope-test@example.test', 'password' => 'test-password']);

    AdminMembership::query()->create([
        'admin_user_id' => $admin->id,
        'scope_type' => ScopeType::Tenant,
        'scope_id' => $tenant->id,
        'role_id' => $role->id,
        'status' => MembershipStatus::Active,
    ]);
})->throws(QueryException::class);

it('rejects a tenant role on a platform membership', function (): void {
    $role = Role::query()->where('name', 'TENANT_OWNER')->firstOrFail();
    $admin = AdminUser::query()->create(['name' => 'Scope Test', 'email' => 'scope-test@example.test', 'password' => 'test-password']);

    AdminMembership::query()->create([
        'admin_user_id' => $admin->id,
        'scope_type' => ScopeType::Platform,
        'scope_id' => null,
        'role_id' => $role->id,
        'status' => MembershipStatus::Active,
    ]);
})->throws(QueryException::class);

it('keeps role names globally unique across scope classifications', function (): void {
    Role::query()->create([
        'name' => 'TENANT_OWNER',
        'scope_type' => ScopeType::Platform,
    ]);
})->throws(QueryException::class);

it('requires a tenant membership scope id to reference a tenant', function (): void {
    $role = Role::query()->where('name', 'TENANT_OWNER')->firstOrFail();
    $admin = AdminUser::query()->create(['name' => 'Scope Test', 'email' => 'scope-test@example.test', 'password' => 'test-password']);

    AdminMembership::query()->create([
        'admin_user_id' => $admin->id,
        'scope_type' => ScopeType::Tenant,
        'scope_id' => Str::uuid()->toString(),
        'role_id' => $role->id,
        'status' => MembershipStatus::Active,
    ]);
})->throws(QueryException::class);

it('has PostgreSQL check constraints for tenant domain and membership states', function (): void {
    $constraintNames = DB::table('pg_constraint')
        ->whereIn('conname', [
            'tenants_status_check',
            'tenant_domains_type_check',
            'tenant_domains_status_check',
            'tenant_kyc_review_mode_check',
            'roles_scope_check',
            'admin_memberships_scope_check',
            'admin_memberships_status_check',
            'admin_invitations_status_check',
        ])
        ->pluck('conname');

    expect($constraintNames)->toHaveCount(8);
});

it('uses PostgreSQL uuid types for business foreign-key columns', function (): void {
    $expected = [
        'tenant_domains.tenant_id',
        'tenant_branding.tenant_id',
        'tenant_locales.tenant_id',
        'tenant_business_settings.tenant_id',
        'tenant_kyc_settings.tenant_id',
        'role_permissions.role_id',
        'role_permissions.permission_id',
        'admin_memberships.admin_user_id',
        'admin_memberships.scope_id',
        'admin_memberships.role_id',
        'admin_invitations.tenant_id',
        'admin_invitations.role_id',
        'admin_invitations.invited_by',
        'audit_logs.tenant_id',
        'audit_logs.actor_id',
        'audit_logs.resource_id',
        'audit_logs.request_id',
    ];
    $actual = collect(DB::select(<<<'SQL'
        SELECT table_name, column_name
        FROM information_schema.columns
        WHERE table_schema = 'public' AND data_type = 'uuid'
        SQL))
        ->map(fn (object $column): string => $column->table_name.'.'.$column->column_name);

    expect($actual)->toContain(...$expected);
});
