<?php

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(fn () => $this->seed());

function authUser(Tenant $tenant, string $email, string $password, UserStatus $status = UserStatus::Active): User
{
    return User::query()->create(['tenant_id' => $tenant->id, 'email' => $email, 'phone' => null, 'password_hash' => Hash::make($password), 'status' => $status, 'email_verified_at' => now()]);
}

it('authenticates active users only within the resolved tenant', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    authUser($tenantA, 'same-auth@example.test', 'PasswordA1234');
    authUser($tenantB, 'same-auth@example.test', 'PasswordB1234');

    $this->post('http://a.localhost/login', ['identifier' => 'same-auth@example.test', 'password' => 'PasswordA1234'])->assertRedirect('/dashboard');
    $this->assertAuthenticated('tenant_user')->assertGuest('tenant_admin');
    auth()->guard('tenant_user')->logout();
    $this->post('http://b.localhost/login', ['identifier' => 'same-auth@example.test', 'password' => 'PasswordA1234'])->assertSessionHasErrors('identifier');
    $this->post('http://b.localhost/login', ['identifier' => 'same-auth@example.test', 'password' => 'PasswordB1234'])->assertRedirect('/dashboard');
});

it('authenticates the same normalized phone independently in two tenants', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    User::query()->create(['tenant_id' => $tenantA->id, 'email' => null, 'phone' => '+60123456789', 'password_hash' => Hash::make('PhonePassA1234'), 'status' => UserStatus::Active, 'phone_verified_at' => now()]);
    User::query()->create(['tenant_id' => $tenantB->id, 'email' => null, 'phone' => '+60123456789', 'password_hash' => Hash::make('PhonePassB1234'), 'status' => UserStatus::Active, 'phone_verified_at' => now()]);
    $this->post('http://a.localhost/login', ['identifier' => '+60 12-345 6789', 'password' => 'PhonePassA1234'])->assertRedirect('/dashboard');
    auth()->guard('tenant_user')->logout();
    $this->post('http://b.localhost/login', ['identifier' => '+60123456789', 'password' => 'PhonePassA1234'])->assertSessionHasErrors('identifier');
    $this->post('http://b.localhost/login', ['identifier' => '+60123456789', 'password' => 'PhonePassB1234'])->assertRedirect('/dashboard');
});

it('returns one generic error for unknown, wrong-password and disabled identities', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    authUser($tenant, 'disabled@example.test', 'StrongPass1234', UserStatus::Disabled);
    foreach ([
        ['identifier' => 'unknown@example.test', 'password' => 'StrongPass1234'],
        ['identifier' => 'user@a.localhost', 'password' => 'wrong-password'],
        ['identifier' => 'disabled@example.test', 'password' => 'StrongPass1234'],
    ] as $payload) {
        $this->post('http://a.localhost/login', $payload)->assertSessionHasErrors('identifier', 'Invalid credentials.');
    }
    expect(AuditLog::query()->where('action', 'USER_LOGIN_FAILED')->count())->toBe(3)
        ->and(AuditLog::query()->get()->toJson())->not->toContain('wrong-password');
});

it('routes suspended users and users of suspended tenants to restricted access', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    authUser($tenantA, 'suspended-user@example.test', 'StrongPass1234', UserStatus::Suspended);
    authUser($tenantB, 'tenant-suspended@example.test', 'StrongPass1234');
    $tenantB->update(['status' => TenantStatus::Suspended, 'suspended_at' => now()]);

    $this->post('http://a.localhost/login', ['identifier' => 'suspended-user@example.test', 'password' => 'StrongPass1234'])->assertRedirect('/account/restricted');
    $this->get('http://a.localhost/account/security')->assertOk();
    auth()->guard('tenant_user')->logout();
    $this->post('http://b.localhost/login', ['identifier' => 'tenant-suspended@example.test', 'password' => 'StrongPass1234'])->assertRedirect('/account/restricted');
    $this->get('http://b.localhost/dashboard')->assertRedirect('/account/restricted');
});

it('redirects an active user away from the restricted account page', function (): void {
    $user = User::query()->where('email', 'user@a.localhost')->firstOrFail();

    $this->actingAs($user, 'tenant_user')
        ->get('http://a.localhost/account/restricted')
        ->assertRedirect('/dashboard');
});

it('blocks registration and login for draft and closed tenants', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $tenantA->update(['status' => TenantStatus::Draft, 'activated_at' => null]);
    $tenantB->update(['status' => TenantStatus::Closed, 'closed_at' => now()]);
    $this->get('http://a.localhost/register')->assertStatus(503);
    $this->get('http://a.localhost/login')->assertStatus(503);
    $this->get('http://b.localhost/login')->assertStatus(503);
});

it('regenerates sessions on login and invalidates them on logout', function (): void {
    $this->app['session']->start();
    $before = $this->app['session']->getId();
    $this->post('http://a.localhost/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])->assertRedirect('/dashboard');
    expect($this->app['session']->getId())->not->toBe($before);
    $this->post('http://a.localhost/logout')->assertRedirect('/login');
    $this->assertGuest('tenant_user');
    expect(AuditLog::query()->where('action', 'USER_LOGOUT')->exists())->toBeTrue();
});

it('keeps end-user and admin identities and guards separate', function (): void {
    $admin = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->post('http://a.localhost/admin/login', ['email' => 'user@a.localhost', 'password' => 'local-password'])->assertSessionHasErrors('email');
    $this->post('http://a.localhost/login', ['identifier' => $admin->email, 'password' => 'local-password'])->assertSessionHasErrors('identifier');

    $user = User::query()->where('email', 'user@a.localhost')->firstOrFail();
    $this->actingAs($user, 'tenant_user')->get('http://a.localhost/admin/users')->assertRedirect('/admin/login');
    auth()->guard('tenant_user')->logout();
    $this->actingAs($admin, 'tenant_admin')->get('http://a.localhost/dashboard')->assertRedirect('/login');
});

it('rate limits login per tenant identifier and ip without cross-tenant pollution', function (): void {
    foreach (range(1, 5) as $_) {
        $this->post('http://a.localhost/login', ['identifier' => 'user@a.localhost', 'password' => 'wrong'])->assertSessionHasErrors('identifier');
    }
    $this->post('http://a.localhost/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])->assertSessionHasErrors('identifier', fn (string $message) => str_contains($message, 'Too many'));
    $this->post('http://b.localhost/login', ['identifier' => 'user@b.localhost', 'password' => 'local-password'])->assertRedirect('/dashboard');
});

it('uses normalized phone identifiers in login rate-limit keys', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    User::query()->create(['tenant_id' => $tenant->id, 'email' => null, 'phone' => '+60123456789', 'password_hash' => Hash::make('PhonePass1234'), 'status' => UserStatus::Active, 'phone_verified_at' => now()]);
    foreach (range(1, 5) as $attempt) {
        $identifier = $attempt % 2 ? '+60 12-345 6789' : '+60123456789';
        $this->post('http://a.localhost/login', ['identifier' => $identifier, 'password' => 'wrong'])->assertSessionHasErrors('identifier');
    }
    $this->post('http://a.localhost/login', ['identifier' => '+60123456789', 'password' => 'PhonePass1234'])
        ->assertSessionHasErrors('identifier', fn (string $message) => str_contains($message, 'Too many'));
});

it('changes password with current-password confirmation and never exposes the hash', function (): void {
    $user = User::query()->where('email', 'user@a.localhost')->firstOrFail();
    $this->actingAs($user, 'tenant_user')->post('http://a.localhost/account/security/password', [
        'current_password' => 'local-password', 'password' => 'NewStrongPass1234', 'password_confirmation' => 'NewStrongPass1234',
    ])->assertRedirect();
    expect(Hash::check('NewStrongPass1234', $user->fresh()->password_hash))->toBeTrue()
        ->and($user->fresh()->toArray())->not->toHaveKey('password_hash')
        ->and(AuditLog::query()->where('action', 'USER_PASSWORD_CHANGED')->exists())->toBeTrue();
});
