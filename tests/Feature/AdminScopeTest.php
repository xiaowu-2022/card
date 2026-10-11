<?php

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Tenant\Models\Tenant;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed();
    $this->withoutVite();
});

it('gives every active company administrator the same menus and operations', function (string $role): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $support = AdminUser::query()->create([
        'name' => 'Support Agent',
        'email' => 'support@a.localhost',
        'password' => 'StrongPass1234',
        'status' => AdminUserStatus::Active,
    ]);
    AdminMembership::query()->create([
        'admin_user_id' => $support->id,
        'scope_type' => ScopeType::Tenant,
        'scope_id' => $tenant->id,
        'role_id' => Role::query()->where('name', $role)->firstOrFail()->id,
        'status' => MembershipStatus::Active,
    ]);

    $this->actingAs($support, 'tenant_admin')->get('http://a.localhost/admin/demo')->assertOk();
    $this->actingAs($support, 'tenant_admin')->get('http://a.localhost/admin/settings/branding')->assertOk();
    $this->actingAs($support, 'tenant_admin')->get('http://a.localhost/admin/team')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.admin.permissions', fn ($permissions) => collect($permissions)->contains('admin_team.manage') && collect($permissions)->contains('kyc.review')));
    $authorization = app(AuthorizationService::class);
    $owner = AdminUser::where('email', 'owner@a.localhost')->sole();
    expect($authorization->permissions($support, ScopeType::Tenant, $tenant->id)->all())
        ->toBe($authorization->permissions($owner, ScopeType::Tenant, $tenant->id)->all());
    $this->get('http://b.localhost/admin/team')->assertForbidden();
    $this->actingAs($support, 'platform_admin')->get('http://admin.localhost/platform/tenants')->assertForbidden();
})->with(['TENANT_ADMIN', 'KYC_REVIEWER', 'CARD_OPERATOR', 'FINANCE_VIEWER', 'SUPPORT']);

it('gives every active platform administrator the same menus and operations', function (string $role): void {
    $auditor = AdminUser::query()->create([
        'name' => 'Platform Auditor',
        'email' => 'auditor@platform.local',
        'password' => 'StrongPass1234',
        'status' => AdminUserStatus::Active,
    ]);
    AdminMembership::query()->create([
        'admin_user_id' => $auditor->id,
        'scope_type' => ScopeType::Platform,
        'scope_id' => null,
        'role_id' => Role::query()->where('name', $role)->firstOrFail()->id,
        'status' => MembershipStatus::Active,
    ]);

    $this->actingAs($auditor, 'platform_admin')->get('http://admin.localhost/platform/tenants')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.admin.permissions', fn ($permissions) => collect($permissions)->contains('tenant.manage') && collect($permissions)->contains('withdrawals.review')));
    $this->get('http://admin.localhost/platform/tenants/create')->assertRedirect();
    $authorization = app(AuthorizationService::class);
    $owner = AdminUser::where('email', 'owner@platform.local')->sole();
    expect($authorization->permissions($auditor, ScopeType::Platform, null)->all())
        ->toBe($authorization->permissions($owner, ScopeType::Platform, null)->all());
    expect($authorization->allows($auditor, ScopeType::Platform, null, 'unknown.permission'))->toBeFalse()
        ->and($authorization->allows($auditor, ScopeType::Platform, 'invalid', 'tenant.manage'))->toBeFalse();
})->with(['PLATFORM_ADMIN', 'PLATFORM_AUDITOR']);

it('rejects inactive accounts revoked memberships and mismatched company scopes', function (): void {
    $admin = AdminUser::where('email', 'owner@a.localhost')->sole();
    $membership = $admin->memberships()->sole();
    $authorization = app(AuthorizationService::class);
    foreach (['SUSPENDED', 'REVOKED'] as $status) {
        $membership->update(['status' => $status]);
        expect($authorization->permissions($admin, ScopeType::Tenant, $membership->scope_id))->toBeEmpty();
        $this->actingAs($admin, 'tenant_admin')->get('http://a.localhost/admin/team')->assertForbidden();
    }
    $membership->update(['status' => 'ACTIVE']);
    expect($authorization->allows($admin, ScopeType::Tenant, Tenant::where('slug', 'tenant-b')->sole()->id, 'admin_team.manage'))->toBeFalse()
        ->and($authorization->allows($admin, ScopeType::Tenant, null, 'admin_team.manage'))->toBeFalse()
        ->and($authorization->allows($admin, ScopeType::Tenant, $membership->scope_id, 'tenant.manage'))->toBeFalse();
    $membership->update(['role_id' => Role::where('name', 'SUPPORT_AGENT')->sole()->id]);
    expect($authorization->permissions($admin, ScopeType::Tenant, $membership->scope_id))->toBeEmpty();
    $membership->update(['role_id' => Role::where('name', 'TENANT_ADMIN')->sole()->id]);
    $admin->update(['status' => 'SUSPENDED']);
    expect($authorization->permissions($admin, ScopeType::Tenant, $membership->scope_id))->toBeEmpty();
    $this->actingAs($admin, 'tenant_admin')->get('http://a.localhost/admin/team')->assertForbidden();
});
