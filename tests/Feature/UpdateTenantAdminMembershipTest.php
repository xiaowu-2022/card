<?php

use App\Application\Admin\UpdateTenantAdminMembershipAction;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed();
    $this->company = Tenant::query()->where('slug', 'tenant-a')->sole();
    $this->otherCompany = Tenant::query()->where('slug', 'tenant-b')->sole();
    $this->actor = AdminUser::query()->where('email', 'owner@platform.local')->sole();
    $this->admin = AdminUser::query()->create(['name' => 'Member', 'email' => 'member@example.test', 'password' => Hash::make('MemberPassword123'), 'status' => 'ACTIVE']);
    $role = Role::query()->where('scope_type', ScopeType::Tenant)->where('name', 'TENANT_ADMIN')->sole();
    $this->membership = AdminMembership::query()->create(['admin_user_id' => $this->admin->id, 'scope_type' => ScopeType::Tenant, 'scope_id' => $this->company->id, 'role_id' => $role->id, 'status' => 'ACTIVE']);
    $this->otherMembership = AdminMembership::query()->create(['admin_user_id' => $this->admin->id, 'scope_type' => ScopeType::Tenant, 'scope_id' => $this->otherCompany->id, 'role_id' => $role->id, 'status' => 'ACTIVE']);
    $this->base = 'http://admin.localhost/platform/tenants/'.$this->company->id.'/configuration/team';
    $this->url = $this->base.'/memberships/'.$this->membership->id;
    $this->data = ['role' => 'SUPPORT', 'status' => 'SUSPENDED'];
});

it('updates only the selected company membership and audits once with actor and timestamp', function (): void {
    $identity = $this->admin->fresh()->getAttributes();
    $this->actingAs($this->actor, 'platform_admin')->from($this->base)->put($this->url, $this->data)->assertRedirect($this->base)->assertSessionHasNoErrors();
    expect($this->membership->fresh()->role->name)->toBe('SUPPORT')
        ->and($this->membership->fresh()->status->value)->toBe('SUSPENDED')
        ->and($this->otherMembership->fresh()->status->value)->toBe('ACTIVE')
        ->and($this->otherMembership->fresh()->role->name)->toBe('TENANT_ADMIN')
        ->and($this->admin->fresh()->getAttributes())->toBe($identity);
    $audit = AuditLog::query()->where('action', 'ADMIN_MEMBERSHIP_UPDATED')->sole();
    expect($audit->actor_id)->toBe($this->actor->id)->and($audit->tenant_id)->toBe($this->company->id)
        ->and($audit->created_at)->not->toBeNull()
        ->and(json_encode($audit->toArray()))->not->toContain('local-password', $this->admin->password);
    $this->put($this->url, $this->data)->assertRedirect()->assertSessionHasNoErrors();
    expect(AuditLog::query()->where('action', 'ADMIN_MEMBERSHIP_UPDATED')->count())->toBe(1);
    $this->get($this->base)->assertOk()->assertInertia(fn ($page) => $page->has('team.members', 2)
        ->where('team.members.0.accountStatus', 'ACTIVE'));
    $this->actingAs($this->admin, 'tenant_admin')->get('http://a.localhost/admin/team')->assertForbidden();
    expect(app(AuthorizationService::class)->hasActiveMembership($this->admin, ScopeType::Tenant, $this->otherCompany->id))->toBeTrue();
});

it('can resume a suspended membership through the same confirmed flow', function (): void {
    $this->membership->update(['status' => 'SUSPENDED']);
    $this->actingAs($this->actor, 'platform_admin')->put($this->url, [...$this->data, 'status' => 'ACTIVE'])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->membership->fresh()->status->value)->toBe('ACTIVE');
});

it('rejects a membership belonging to another company and protects owners and revoked memberships', function (): void {
    $this->actingAs($this->actor, 'platform_admin')->put($this->base.'/memberships/'.$this->otherMembership->id, $this->data)->assertNotFound();
    $ownerMembership = AdminMembership::query()->where('scope_id', $this->company->id)->whereHas('role', fn ($query) => $query->where('name', 'TENANT_OWNER'))->sole();
    $this->putJson($this->base.'/memberships/'.$ownerMembership->id, $this->data)->assertForbidden();
    $this->membership->update(['status' => 'REVOKED']);
    $this->putJson($this->url, $this->data)->assertForbidden();
    expect(AuditLog::query()->where('action', 'ADMIN_MEMBERSHIP_UPDATED')->count())->toBe(0);
});

it('rejects credential changes invalid roles statuses', function (array $changes, string $field): void {
    $this->actingAs($this->actor, 'platform_admin')->put($this->url, [...$this->data, ...$changes])->assertSessionHasErrors($field);
    expect($this->membership->fresh()->role->name)->toBe('TENANT_ADMIN')
        ->and($this->membership->fresh()->status->value)->toBe('ACTIVE')
        ->and(session()->get('_old_input.current_password'))->toBeNull();
})->with([
    [['role' => 'TENANT_OWNER'], 'role'], [['role' => 'PLATFORM_ADMIN'], 'role'],
    [['status' => 'REVOKED'], 'status'],
    [['email' => 'changed@example.test'], 'email'], [['password' => 'changed'], 'password'],
    [['tenant_id' => 'spoofed'], 'tenant_id'],
]);

it('requires active platform administration and rechecks membership inside the action', function (): void {
    $companyOwner = AdminUser::query()->where('email', 'owner@a.localhost')->sole();
    $this->actingAs($companyOwner, 'platform_admin')->put($this->url, $this->data)->assertForbidden();
    expect(fn () => app(UpdateTenantAdminMembershipAction::class)->execute($this->company, $this->membership->id, $companyOwner, 'SUPPORT', 'ACTIVE'))->toThrow(DomainException::class);
    $this->actor->memberships()->where('scope_type', ScopeType::Platform)->update(['status' => 'SUSPENDED']);
    $this->actingAs($this->actor, 'platform_admin')->put($this->url, $this->data)->assertForbidden();
    expect(fn () => app(UpdateTenantAdminMembershipAction::class)->execute($this->company, $this->membership->id, $this->actor, 'SUPPORT', 'ACTIVE'))->toThrow(DomainException::class);
});

it('creates administrators from the platform dialog endpoint with password confirmation', function (): void {
    $this->actingAs($this->actor, 'platform_admin')->from($this->base)->post($this->base.'/administrators', [
        'name' => 'Dialog Admin', 'email' => 'dialog@example.test', 'role' => 'SUPPORT',
        'password' => 'DialogPassword123', 'password_confirmation' => 'DialogPassword123',
    ])->assertRedirect($this->base)->assertSessionHasNoErrors();
    $created = AdminUser::query()->where('email', 'dialog@example.test')->sole();
    expect(Hash::check('DialogPassword123', $created->password))->toBeTrue()
        ->and($created->memberships()->sole()->scope_id)->toBe($this->company->id);
});
