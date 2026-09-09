<?php

use App\Application\User\ReactivateUserAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\RegistrationChallenge;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;

beforeEach(fn () => $this->seed());

it('lists and reads users only within the tenant admin scope', function (): void {
    $adminA = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $userA = User::query()->where('email', 'user@a.localhost')->firstOrFail();
    $userB = User::query()->where('email', 'user@b.localhost')->firstOrFail();
    $this->actingAs($adminA, 'tenant_admin')->get('http://a.localhost/admin/users')->assertOk()->assertInertia(fn ($page) => $page->component('tenant-admin/Users')->where('users.data.0.id', $userA->id));
    $this->actingAs($adminA, 'tenant_admin')->get("http://a.localhost/admin/users/{$userB->id}")->assertNotFound();
});

it('suspends and reactivates users without deleting their account', function (): void {
    $admin = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $user = User::query()->where('email', 'user@a.localhost')->firstOrFail();
    $this->actingAs($admin, 'tenant_admin')->post("http://a.localhost/admin/users/{$user->id}/suspend")->assertRedirect();
    expect($user->fresh()->status)->toBe(UserStatus::Suspended)
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'USER_SUSPENDED')->where('resource_id', $user->id)->exists())->toBeTrue();
    $this->post("http://a.localhost/admin/users/{$user->id}/reactivate")->assertRedirect();
    expect($user->fresh()->status)->toBe(UserStatus::Active)
        ->and(AuditLog::query()->where('action', 'USER_REACTIVATED')->where('resource_id', $user->id)->exists())->toBeTrue();
});

it('does not reactivate disabled users through the ordinary action', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $admin = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $user = User::query()->where('email', 'user@a.localhost')->firstOrFail();
    $user->update(['status' => UserStatus::Disabled]);
    expect(fn () => app(ReactivateUserAction::class)->execute($tenant->id, $user->id, $admin))->toThrow(DomainException::class);
});

it('ignores client-controlled tenant and status fields during registration', function (): void {
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $challenge = RegistrationChallenge::query()->create([
        'tenant_id' => $tenantA->id, 'channel' => 'EMAIL', 'destination' => 'allowlist@example.test', 'code_hash' => str_repeat('a', 64), 'status' => 'VERIFIED', 'expires_at' => now()->addMinutes(10), 'verified_at' => now(),
    ]);
    $this->withSession(['registration.challenge_ids' => [$challenge->id]])->post("http://a.localhost/register/challenges/{$challenge->id}/complete", [
        'password' => 'StrongPass1234',
        'password_confirmation' => 'StrongPass1234',
        'tenant_id' => $tenantB->id,
        'status' => 'DISABLED',
        'email_verified_at' => now()->toIso8601String(),
        'phone_verified_at' => now()->toIso8601String(),
        'last_login_at' => now()->toIso8601String(),
        'password_hash' => 'attacker-controlled',
        'attempt_count' => 99,
        'verified_at' => null,
        'consumed_at' => null,
        'locked_at' => now()->toIso8601String(),
    ])->assertRedirect('/dashboard');
    $user = User::query()->where('email', 'allowlist@example.test')->firstOrFail();
    expect($user->tenant_id)->toBe($tenantA->id)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->phone_verified_at)->toBeNull()
        ->and($user->last_login_at)->toBeNull()
        ->and($challenge->fresh()->attempt_count)->toBe(0)
        ->and($challenge->fresh()->locked_at)->toBeNull()
        ->and($challenge->fresh()->consumed_at)->not->toBeNull();
});
