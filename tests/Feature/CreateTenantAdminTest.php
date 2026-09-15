<?php

use App\Application\Admin\CreateTenantAdminAction;
use App\Domain\Admin\Models\AdminInvitation;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->seed();
    Mail::fake();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->data = ['name' => 'Direct Admin', 'email' => 'direct-admin@example.test', 'password' => 'DirectStrong1234',
        'password_confirmation' => 'DirectStrong1234', 'role' => 'TENANT_ADMIN', 'current_password' => 'local-password'];
});

it('creates a hashed active administrator and exact-company membership with no invitation or email', function (): void {
    $invitations = AdminInvitation::query()->count();
    $this->actingAs($this->owner, 'platform_admin')->post("http://admin.localhost/platform/tenants/{$this->tenant->id}/configuration/team/administrators", $this->data + ['tenant_id' => 'ignored-client-value'])->assertRedirect()->assertSessionHasNoErrors();
    $admin = AdminUser::query()->where('email', $this->data['email'])->sole();
    expect(Hash::check($this->data['password'], $admin->password))->toBeTrue()
        ->and($admin->password)->not->toBe($this->data['password'])
        ->and($admin->email_verified_at)->toBeNull()
        ->and($admin->status->value)->toBe('ACTIVE');
    $membership = $admin->memberships()->sole();
    expect($membership->scope_id)->toBe($this->tenant->id)->and($membership->scope_type->value)->toBe('TENANT')
        ->and($membership->role->name)->toBe('TENANT_ADMIN')->and($membership->status->value)->toBe('ACTIVE')
        ->and(AdminInvitation::query()->count())->toBe($invitations);
    $audit = AuditLog::query()->where('action', 'ADMIN_ACCOUNT_CREATED')->sole();
    expect($audit->actor_id)->toBe($this->owner->id)->and($audit->tenant_id)->toBe($this->tenant->id)
        ->and(json_encode($audit->toArray()))->not->toContain($this->data['password'], 'local-password', $admin->password);
    Mail::assertNothingSent();
    $this->get("http://admin.localhost/platform/tenants/{$this->tenant->id}/configuration/team")->assertDontSee($this->data['password'])->assertDontSee($admin->password);
    Auth::guard('platform_admin')->logout();
    $this->post('http://a.localhost/admin/login', ['email' => $admin->email, 'password' => $this->data['password']])->assertRedirect();
    $this->assertAuthenticatedAs($admin, 'tenant_admin');
    Auth::guard('tenant_admin')->logout();
    $this->post('http://b.localhost/admin/login', ['email' => $admin->email, 'password' => $this->data['password']])->assertSessionHasErrors('email');
    $this->post('http://admin.localhost/platform/login', ['email' => $admin->email, 'password' => $this->data['password']])->assertSessionHasErrors('email');
});

it('never overwrites or attaches existing identities in any scope', function (string $email): void {
    $existing = AdminUser::query()->where('email', $email)->sole();
    $hash = $existing->password;
    $count = AdminMembership::query()->count();
    $this->actingAs($this->owner, 'platform_admin')->postJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/configuration/team/administrators", [...$this->data, 'email' => strtoupper($email)])->assertConflict();
    expect($existing->fresh()->password)->toBe($hash)->and(AdminMembership::query()->count())->toBe($count)
        ->and(AuditLog::query()->where('action', 'ADMIN_ACCOUNT_CREATED')->count())->toBe(0);
})->with(['owner@a.localhost', 'owner@b.localhost', 'owner@platform.local']);

it('rejects weak passwords mismatched confirmation incorrect actor passwords and owner/platform roles', function (array $changes, string $field): void {
    $this->actingAs($this->owner, 'platform_admin')->post("http://admin.localhost/platform/tenants/{$this->tenant->id}/configuration/team/administrators", [...$this->data, ...$changes])->assertSessionHasErrors($field);
    expect(AdminUser::query()->where('email', $this->data['email'])->exists())->toBeFalse();
    foreach (['password', 'password_confirmation', 'current_password'] as $key) {
        expect(session()->get('_old_input.'.$key))->toBeNull();
    }
})->with([
    [['password' => '123', 'password_confirmation' => '123'], 'password'],
    [['password_confirmation' => 'different'], 'password'],
    [['current_password' => 'wrong'], 'current_password'],
    [['role' => 'TENANT_OWNER'], 'role'],
    [['role' => 'PLATFORM_ADMIN'], 'role'],
]);

it('rejects company administrators and read-only Platform memberships', function (): void {
    $companyOwner = AdminUser::query()->where('email', 'owner@a.localhost')->sole();
    $this->actingAs($companyOwner, 'tenant_admin')->post('http://b.localhost/admin/team/administrators', $this->data)->assertForbidden();
    $this->post('http://a.localhost/admin/team/administrators', $this->data)->assertForbidden();
    expect(fn () => app(CreateTenantAdminAction::class)->execute($this->tenant, $companyOwner, 'Direct', $this->data['email'], $this->data['password'], 'TENANT_ADMIN', 'local-password'))->toThrow(HttpException::class);
    $membership = $this->owner->memberships()->where('scope_type', 'PLATFORM')->whereNull('scope_id')->sole();
    $membership->update(['role_id' => Role::query()->where('name', 'PLATFORM_AUDITOR')->sole()->id]);
    $this->actingAs($this->owner, 'platform_admin')->post("http://admin.localhost/platform/tenants/{$this->tenant->id}/configuration/team/administrators", $this->data)->assertForbidden();
    expect(AdminUser::query()->where('email', $this->data['email'])->exists())->toBeFalse();
});

it('treats a repeated create as a duplicate without extra memberships or audit', function (): void {
    $this->actingAs($this->owner, 'platform_admin')->postJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/configuration/team/administrators", $this->data)->assertRedirect();
    $this->postJson("http://admin.localhost/platform/tenants/{$this->tenant->id}/configuration/team/administrators", $this->data)->assertConflict();
    $admin = AdminUser::query()->where('email', $this->data['email'])->sole();
    expect($admin->memberships()->count())->toBe(1)->and(AuditLog::query()->where('action', 'ADMIN_ACCOUNT_CREATED')->count())->toBe(1);
});
