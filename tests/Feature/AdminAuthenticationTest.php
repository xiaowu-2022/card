<?php

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\MembershipStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenant\Models\Tenant;

beforeEach(fn () => $this->seed());

it('authenticates a platform administrator only through the platform guard', function (): void {
    $this->post('http://admin.localhost/platform/login', ['email' => 'owner@platform.local', 'password' => 'local-password'])
        ->assertRedirect('/platform/tenants');

    $this->assertAuthenticated('platform_admin')->assertGuest('tenant_admin');
    expect(AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail()->last_login_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'ADMIN_LOGIN_SUCCESS')->exists())->toBeTrue();
});

it('authenticates a tenant administrator only for their resolved tenant', function (): void {
    $this->post('http://a.localhost/admin/login', ['email' => 'owner@a.localhost', 'password' => 'local-password'])
        ->assertRedirect('/admin/onboarding');
    $this->assertAuthenticated('tenant_admin')->assertGuest('platform_admin');

    $this->post('http://b.localhost/admin/login', ['email' => 'owner@a.localhost', 'password' => 'local-password'])
        ->assertSessionHasErrors('email');
});

it('rejects incorrect credentials and inactive identities or memberships', function (): void {
    $this->post('http://a.localhost/admin/login', ['email' => 'owner@a.localhost', 'password' => 'wrong'])->assertSessionHasErrors('email');

    $admin = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $admin->update(['status' => AdminUserStatus::Suspended]);
    $this->post('http://a.localhost/admin/login', ['email' => $admin->email, 'password' => 'local-password'])->assertSessionHasErrors('email');

    $admin->update(['status' => AdminUserStatus::Active]);
    AdminMembership::query()->where('admin_user_id', $admin->id)->update(['status' => MembershipStatus::Suspended]);
    $this->post('http://a.localhost/admin/login', ['email' => $admin->email, 'password' => 'local-password'])->assertSessionHasErrors('email');
});

it('does not permit a tenant-only identity on the platform surface', function (): void {
    $this->post('http://admin.localhost/platform/login', ['email' => 'owner@a.localhost', 'password' => 'local-password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest('platform_admin');
});

it('does not permit a platform-only identity on a tenant surface', function (): void {
    $this->post('http://a.localhost/admin/login', ['email' => 'owner@platform.local', 'password' => 'local-password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest('tenant_admin');
});

it('regenerates the session identifier after login', function (): void {
    $this->app['session']->start();
    $before = $this->app['session']->getId();
    $this->post('http://admin.localhost/platform/login', ['email' => 'owner@platform.local', 'password' => 'local-password'])
        ->assertRedirect('/platform/tenants');

    expect($this->app['session']->getId())->not->toBe($before);
});

it('enforces per-surface login throttling', function (): void {
    foreach (range(1, 5) as $_) {
        $this->from('http://admin.localhost/platform/login')->post('http://admin.localhost/platform/login', ['email' => 'unknown@example.test', 'password' => 'incorrect'])->assertRedirect();
    }
    $this->post('http://admin.localhost/platform/login', ['email' => 'unknown@example.test', 'password' => 'incorrect'])
        ->assertSessionHasErrors('email', fn (string $message) => str_contains($message, 'Too many sign-in attempts'));
});

it('logs out and invalidates the selected guard session', function (): void {
    $admin = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($admin, 'platform_admin')->post('http://admin.localhost/platform/logout')->assertRedirect('/platform/login');
    $this->assertGuest('platform_admin');
    expect(AuditLog::query()->where('action', 'ADMIN_LOGOUT')->exists())->toBeTrue();
});

it('refuses client tenant ids when authorizing tenant admin access', function (): void {
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $adminA = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();

    $this->actingAs($adminA, 'tenant_admin')
        ->withHeader('X-Tenant-ID', $tenantB->id)
        ->get('http://a.localhost/admin/demo?tenant_id='.$tenantB->id)
        ->assertOk();

    expect(AdminMembership::query()->where('admin_user_id', $adminA->id)->where('scope_type', ScopeType::Tenant)->value('scope_id'))
        ->not->toBe($tenantB->id);
});
