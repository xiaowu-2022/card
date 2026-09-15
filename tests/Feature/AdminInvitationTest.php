<?php

use App\Application\Admin\AcceptAdminInvitationAction;
use App\Application\Admin\IssueAdminInvitationAction;
use App\Application\Admin\ResendAdminInvitationAction;
use App\Domain\Admin\Enums\InvitationStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminInvitation;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Tenant\Models\Tenant;
use App\Mail\AdminInvitationMail;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => $this->seed());

it('stores only a hash and builds the invitation on the correct tenant host', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $role = Role::query()->where('name', 'TENANT_ADMIN')->firstOrFail();
    $actor = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $issued = app(IssueAdminInvitationAction::class)->execute($tenant, 'new@example.test', $role, $actor);

    expect($issued->rawToken)->toHaveLength(64)
        ->and($issued->url)->toStartWith('http://a.localhost:8000/admin/invitations/')
        ->and($issued->invitation->token_hash)->toBe(hash('sha256', $issued->rawToken))
        ->and(json_encode($issued->invitation->fresh()->toArray()))->not->toContain($issued->rawToken);
});

it('accepts a valid invitation once and creates a tenant-scoped membership', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $issued = app(IssueAdminInvitationAction::class)->execute($tenant, 'fresh@example.test', Role::query()->where('name', 'TENANT_ADMIN')->firstOrFail(), AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());
    $accepted = app(AcceptAdminInvitationAction::class)->execute($issued->rawToken, $tenant->id, 'Fresh Admin', 'StrongPass1234');

    expect($accepted->invitation->status)->toBe(InvitationStatus::Accepted)
        ->and($accepted->membership->scope_type)->toBe(ScopeType::Tenant)
        ->and($accepted->membership->scope_id)->toBe($tenant->id);
    expect(fn () => app(AcceptAdminInvitationAction::class)->execute($issued->rawToken, $tenant->id, 'Again', 'StrongPass1234'))->toThrow(DomainException::class);
});

it('rejects an invitation on another tenant host and when expired or cancelled', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $issued = app(IssueAdminInvitationAction::class)->execute($tenantA, 'host@example.test', Role::query()->where('name', 'TENANT_ADMIN')->firstOrFail(), AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());

    $this->get("http://b.localhost/admin/invitations/{$issued->rawToken}")->assertNotFound();
    $issued->invitation->update(['expires_at' => now()->subMinute()]);
    expect(fn () => app(AcceptAdminInvitationAction::class)->inspect($issued->rawToken, $tenantA->id))->toThrow(DomainException::class);
    $issued->invitation->update(['expires_at' => now()->addHour(), 'status' => InvitationStatus::Cancelled]);
    expect(fn () => app(AcceptAdminInvitationAction::class)->inspect($issued->rawToken, $tenantA->id))->toThrow(DomainException::class);
    expect($tenantB->id)->not->toBe($tenantA->id);
});

it('reuses an existing admin identity without copying memberships', function (): void {
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $existing = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $issued = app(IssueAdminInvitationAction::class)->execute($tenantB, $existing->email, Role::query()->where('name', 'SUPPORT')->firstOrFail(), AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail());
    $accepted = app(AcceptAdminInvitationAction::class)->execute($issued->rawToken, $tenantB->id, $existing->name, 'local-password');

    expect($accepted->admin->id)->toBe($existing->id)
        ->and(AdminMembership::query()->where('admin_user_id', $existing->id)->count())->toBe(2);
});

it('resending invalidates the old token and sends only the replacement link', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $actor = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $old = app(IssueAdminInvitationAction::class)->execute($tenant, 'resend@example.test', Role::query()->where('name', 'SUPPORT')->firstOrFail(), $actor);
    $new = app(ResendAdminInvitationAction::class)->execute($tenant, $old->invitation->id, $actor);

    expect($old->invitation->fresh()->status)->toBe(InvitationStatus::Cancelled)
        ->and($new->rawToken)->not->toBe($old->rawToken)
        ->and(AdminInvitation::query()->where('tenant_id', $tenant->id)->where('email', 'resend@example.test')->where('status', InvitationStatus::Pending)->count())->toBe(1);
    Mail::assertSent(AdminInvitationMail::class, 1);
});
