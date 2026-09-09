<?php

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Tenant\Models\Tenant;

beforeEach(fn () => $this->seed());

it('enforces tenant permissions in middleware instead of trusting authentication', function (): void {
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
        'role_id' => Role::query()->where('name', 'SUPPORT')->firstOrFail()->id,
        'status' => MembershipStatus::Active,
    ]);

    $this->actingAs($support, 'tenant_admin')->get('http://a.localhost/admin/demo')->assertOk();
    $this->actingAs($support, 'tenant_admin')->get('http://a.localhost/admin/settings/branding')->assertForbidden();
    $this->actingAs($support, 'tenant_admin')->get('http://a.localhost/admin/team')->assertForbidden();
});

it('enforces platform permissions independently of the platform guard', function (): void {
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
        'role_id' => Role::query()->where('name', 'PLATFORM_AUDITOR')->firstOrFail()->id,
        'status' => MembershipStatus::Active,
    ]);

    $this->actingAs($auditor, 'platform_admin')->get('http://admin.localhost/platform/tenants')->assertOk();
    $this->actingAs($auditor, 'platform_admin')->get('http://admin.localhost/platform/tenants/create')->assertForbidden();
});
