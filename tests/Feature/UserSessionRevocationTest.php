<?php

use App\Application\User\ChangeUserPasswordAction;
use App\Application\User\RevokeOtherUserSessionsAction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    $this->seed();
    $this->user = User::query()->where('email', 'user@a.localhost')->firstOrFail();
    $this->guardKey = Auth::guard('tenant_user')->getName();
});

it('binds the current version at login and does not expose it in the user model', function (): void {
    $this->post('http://a.localhost/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])
        ->assertRedirect('/dashboard')->assertSessionHas('tenant_user_session_version', 0);
    expect($this->user->toArray())->not->toHaveKey('session_version');
});

it('preserves the password-changing device and rejects an older device on its next request', function (): void {
    $this->post('http://a.localhost/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])->assertRedirect();
    $this->post('http://a.localhost/account/security/password', ['current_password' => 'local-password', 'password' => 'UpdatedPassword123', 'password_confirmation' => 'UpdatedPassword123'])
        ->assertRedirect()->assertSessionHas('tenant_user_session_version', 1);
    Auth::forgetGuards();
    $this->get('http://a.localhost/account/security')->assertOk();
    Auth::forgetGuards();
    $this->withSession([$this->guardKey => $this->user->id, 'tenant_user_session_version' => 0])
        ->get('http://a.localhost/account/security')->assertRedirect('/login')->assertSessionMissing($this->guardKey);
});

it('rejects legacy versionless sessions after revocation without clearing the admin guard', function (): void {
    $this->post('http://a.localhost/admin/login', ['email' => 'owner@a.localhost', 'password' => 'local-password'])->assertRedirect();
    $this->post('http://a.localhost/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])->assertRedirect();
    app(RevokeOtherUserSessionsAction::class)->execute($this->user->tenant_id, $this->user->id, 'local-password');
    $this->app['session']->forget('tenant_user_session_version');
    Auth::forgetGuards();
    $this->get('http://a.localhost/account/security')->assertRedirect('/login');
    $this->assertGuest('tenant_user')->assertAuthenticated('tenant_admin');
    $this->get('http://a.localhost/admin/team')->assertOk();
});

it('allows untouched legacy sessions only while the database version is zero', function (): void {
    $this->withSession([$this->guardKey => $this->user->id])->get('http://a.localhost/account/security')->assertOk();
    app(RevokeOtherUserSessionsAction::class)->execute($this->user->tenant_id, $this->user->id, 'local-password');
    Auth::forgetGuards();
    $this->get('http://a.localhost/account/security')->assertRedirect('/login');
});

it('requires password and explicit confirmation before other-device revocation', function (): void {
    $this->post('http://a.localhost/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])->assertRedirect();
    $beforePassword = $this->user->password_hash;
    $this->post('http://a.localhost/account/security/sessions/revoke', ['current_password' => 'local-password'])->assertSessionHasErrors('confirmed');
    $this->post('http://a.localhost/account/security/sessions/revoke', ['current_password' => 'wrong', 'confirmed' => true])->assertSessionHasErrors('form');
    expect($this->user->fresh()->session_version)->toBe(0);
    $this->post('http://a.localhost/account/security/sessions/revoke', ['current_password' => 'local-password', 'confirmed' => true])
        ->assertRedirect()->assertSessionHas('tenant_user_session_version', 1);
    expect($this->user->fresh()->password_hash)->toBe($beforePassword)
        ->and(AuditLog::query()->where('action', 'USER_OTHER_SESSIONS_REVOKED')->count())->toBe(1);
    Auth::forgetGuards();
    $this->get('http://a.localhost/account/security')->assertOk();
});

it('cannot revoke a user in another company or revoke using a stale current password', function (): void {
    $other = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    expect(fn () => app(RevokeOtherUserSessionsAction::class)->execute($other->id, $this->user->id, 'local-password'))
        ->toThrow(ModelNotFoundException::class);
    app(ChangeUserPasswordAction::class)->execute($this->user->tenant_id, $this->user->id, 'local-password', 'UpdatedPassword123');
    expect(fn () => app(RevokeOtherUserSessionsAction::class)->execute($this->user->tenant_id, $this->user->id, 'local-password'))
        ->toThrow(DomainException::class)
        ->and($this->user->fresh()->session_version)->toBe(1);
});

it('permits suspended account security but rejects disabled users and closed companies', function (): void {
    $this->user->update(['status' => UserStatus::Suspended]);
    $this->post('http://a.localhost/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])->assertRedirect('/account/restricted');
    $this->post('http://a.localhost/account/security/sessions/revoke', ['current_password' => 'local-password', 'confirmed' => true])->assertRedirect();
    $this->user->update(['status' => UserStatus::Disabled]);
    expect(fn () => app(RevokeOtherUserSessionsAction::class)->execute($this->user->tenant_id, $this->user->id, 'local-password'))->toThrow(DomainException::class);
    $this->user->update(['status' => UserStatus::Active]);
    Tenant::query()->whereKey($this->user->tenant_id)->update(['status' => TenantStatus::Closed, 'closed_at' => now()]);
    expect(fn () => app(ChangeUserPasswordAction::class)->execute($this->user->tenant_id, $this->user->id, 'local-password', 'UpdatedPassword123'))->toThrow(DomainException::class);
});
