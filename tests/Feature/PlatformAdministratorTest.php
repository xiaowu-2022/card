<?php

use App\Application\Admin\CreatePlatformAdminAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    $this->owner = AdminUser::query()->where('email', 'owner@platform.local')->sole();
    $this->data = ['name' => 'SaaS operator', 'email' => 'new-saas@example.test', 'password' => 'NewPlatformPassword123',
        'password_confirmation' => 'NewPlatformPassword123', 'role' => 'PLATFORM_ADMIN'];
});

it('creates a platform-only login with safe audited provenance and rejects repeated creation', function (): void {
    $this->actingAs($this->owner, 'platform_admin')->post('http://admin.localhost/platform/administrators', $this->data)->assertRedirect()->assertSessionHasNoErrors();
    $created = AdminUser::query()->where('email', $this->data['email'])->sole();
    expect(Hash::check($this->data['password'], $created->password))->toBeTrue();
    $membership = $created->memberships()->sole();
    expect($membership->scope_type->value)->toBe('PLATFORM')->and($membership->scope_id)->toBeNull();
    $audit = AuditLog::query()->where('action', 'ADMIN_ACCOUNT_CREATED')->where('resource_id', $created->id)->sole();
    expect($audit->actor_id)->toBe($this->owner->id)->and($audit->created_at)->not->toBeNull()
        ->and(json_encode($audit->toArray()))->not->toContain($this->data['password'], 'local-password', $created->password);
    $this->postJson('http://admin.localhost/platform/administrators', $this->data)->assertConflict();
    $this->get('http://admin.localhost/platform/administrators')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('platform/Administrators')->has('team.members', 2)->missing('team.members.0.password'));
    Auth::guard('platform_admin')->logout();
    $this->post('http://admin.localhost/platform/login', ['email' => $created->email, 'password' => $this->data['password']])->assertRedirect();
    $this->assertAuthenticatedAs($created, 'platform_admin');
    $this->post('http://a.localhost/admin/login', ['email' => $created->email, 'password' => $this->data['password']])->assertSessionHasErrors('email');
});

it('rejects invalid roles and passwords without retaining password input', function (array $changes, string $field): void {
    $this->actingAs($this->owner, 'platform_admin')->post('http://admin.localhost/platform/administrators', [...$this->data, ...$changes])->assertSessionHasErrors($field);
    expect(AdminUser::query()->where('email', $this->data['email'])->exists())->toBeFalse();
    foreach (['password', 'password_confirmation', 'current_password'] as $key) {
        expect(session()->get('_old_input.'.$key))->toBeNull();
    }
})->with([
    [['role' => 'PLATFORM_OWNER'], 'role'], [['role' => 'TENANT_ADMIN'], 'role'],
    [['password' => 'weak', 'password_confirmation' => 'weak'], 'password'],
    [['password_confirmation' => 'different'], 'password'],
]);

it('refuses company actors suspended actors and read-only platform roles at the action boundary', function (): void {
    $company = AdminUser::query()->where('email', 'owner@a.localhost')->sole();
    $this->actingAs($company, 'platform_admin')->post('http://admin.localhost/platform/administrators', $this->data)->assertForbidden();
    expect(fn () => app(CreatePlatformAdminAction::class)->execute($company, 'No', 'no@example.test', $this->data['password'], 'PLATFORM_ADMIN'))->toThrow(DomainException::class);
    $this->owner->memberships()->sole()->update(['role_id' => Role::query()->where('name', 'PLATFORM_AUDITOR')->sole()->id]);
    $this->actingAs($this->owner, 'platform_admin')->post('http://admin.localhost/platform/administrators', $this->data)->assertForbidden();
    expect(fn () => app(CreatePlatformAdminAction::class)->execute($this->owner, 'No', 'no@example.test', $this->data['password'], 'PLATFORM_ADMIN'))->toThrow(DomainException::class);
    $this->owner->update(['status' => 'SUSPENDED']);
    expect(fn () => app(CreatePlatformAdminAction::class)->execute($this->owner, 'No', 'no@example.test', $this->data['password'], 'PLATFORM_ADMIN'))->toThrow(DomainException::class);
});

it('never resets or attaches an existing company identity', function (): void {
    $company = AdminUser::query()->where('email', 'owner@a.localhost')->sole();
    $password = $company->password;
    $this->actingAs($this->owner, 'platform_admin')->postJson('http://admin.localhost/platform/administrators', [...$this->data, 'email' => strtoupper($company->email)])->assertConflict();
    expect($company->fresh()->password)->toBe($password)->and($company->memberships()->count())->toBe(1);
});
