<?php

use App\Application\User\ChangeUserPasswordAction;
use App\Application\User\ResetForgottenUserPasswordAction;
use App\Application\User\RevokeOtherUserSessionsAction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notification\Contracts\EmailVerificationSender;
use App\Domain\Notification\Contracts\SmsVerificationSender;
use App\Domain\Notification\Exceptions\EmailDeliveryUnknown;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserPasswordReset;
use App\Mail\UserVerificationCodeMail;
use App\Support\Errors\DomainException;
use App\Support\Logging\SensitiveDataRedactor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    Mail::fake();
    $this->user = User::query()->where('email', 'user@a.localhost')->firstOrFail();
    $this->action = app(ResetForgottenUserPasswordAction::class);
});

function recoveryCode(): string
{
    return Mail::sent(UserVerificationCodeMail::class)->last()->code;
}

it('resets only an existing verified account and preserves its identity and financial records', function (): void {
    $before = $this->user->getAttributes();
    $ledger = DB::table('ledger_entries')->count();
    $reset = $this->action->start($this->user->tenant_id, RegistrationChannel::Email, ' USER@A.LOCALHOST ', 'browser', '127.0.0.1', (string) Str::uuid());
    $code = recoveryCode();
    expect(Hash::check('local-password', $this->user->fresh()->password_hash))->toBeTrue();
    $this->action->complete($this->user->tenant_id, $reset->id, 'browser', $code, 'NewPassword1234');
    expect(Hash::check('NewPassword1234', $this->user->fresh()->password_hash))->toBeTrue()
        ->and($this->user->fresh()->session_version)->toBe(1)->and($reset->fresh()->consumed_at)->not->toBeNull()
        ->and(DB::table('ledger_entries')->count())->toBe($ledger)
        ->and(AuditLog::query()->where('action', 'USER_PASSWORD_RESET')->count())->toBe(1);
    foreach (['id', 'tenant_id', 'account_id', 'email', 'phone', 'email_verified_at', 'status'] as $key) {
        expect($this->user->fresh()->getRawOriginal($key))->toBe($before[$key]);
    }
    $this->action->complete($this->user->tenant_id, $reset->id, 'browser', $code, 'DifferentPassword123');
    expect(Hash::check('NewPassword1234', $this->user->fresh()->password_hash))->toBeTrue()
        ->and($this->user->fresh()->session_version)->toBe(1);
});

it('normalizes verified phone recovery and uses the company SMS transport', function (): void {
    $this->user->update(['phone' => '+60123456789', 'phone_verified_at' => now()]);
    $reset = $this->action->start($this->user->tenant_id, RegistrationChannel::Phone, '+60 12-345 6789', 'browser', '127.0.0.1', (string) Str::uuid());
    $sent = app(SmsVerificationSender::class)->messages()[0];
    expect($sent['tenant_id'])->toBe($this->user->tenant_id)->and($sent['destination'])->toBe('+60123456789');
    $this->action->complete($this->user->tenant_id, $reset->id, 'browser', $sent['code'], 'NewPassword1234');
    expect(Hash::check('NewPassword1234', $this->user->fresh()->password_hash))->toBeTrue();
});

it('uses identical public receipt shapes and generic delivery for unknown unverified and disabled accounts', function (): void {
    $userCount = User::query()->count();
    $destinations = ['unknown@example.test'];
    foreach (['unverified', 'disabled'] as $kind) {
        $copy = $this->user->replicate(['account_id']);
        $copy->email = $kind.'@example.test';
        if ($kind === 'unverified') {
            $copy->email_verified_at = null;
        }
        if ($kind === 'disabled') {
            $copy->status = UserStatus::Disabled;
        }
        $copy->save();
        $destinations[] = $copy->email;
    }
    foreach ($destinations as $destination) {
        $reset = $this->action->start($this->user->tenant_id, RegistrationChannel::Email, $destination, 'browser-'.$destination, '127.0.0.1', (string) Str::uuid());
        expect($reset->user_id)->toBeNull()
            ->and(array_keys($this->action->view($this->user->tenant_id, $reset->id, 'browser-'.$destination)))->toBe(['id', 'channel', 'expiresAt']);
        expect(fn () => $this->action->complete($this->user->tenant_id, $reset->id, 'browser-'.$destination, recoveryCode(), 'NewPassword1234'))->toThrow(DomainException::class);
    }
    Mail::assertSent(UserVerificationCodeMail::class, 3);
    expect(User::query()->count())->toBe($userCount + 2)->and(AuditLog::query()->where('action', 'USER_PASSWORD_RESET')->count())->toBe(0);
});

it('never exposes raw contact code hashes or the browser proof', function (): void {
    $reset = $this->action->start($this->user->tenant_id, RegistrationChannel::Email, $this->user->email, 'private-browser-binding', '127.0.0.1', (string) Str::uuid());
    expect($reset->getRawOriginal('destination'))->not->toContain($this->user->email)
        ->and($reset->code_hash)->not->toBe(recoveryCode())
        ->and($reset->toArray())->not->toHaveKeys(['user_id', 'destination', 'code_hash', 'session_hash', 'credential_hash', 'ip_hash'])
        ->and(AuditLog::query()->where('action', 'USER_PASSWORD_RESET_REQUESTED')->get()->toJson())->not->toContain(recoveryCode(), 'private-browser-binding', $this->user->email);
    expect(app(SensitiveDataRedactor::class)->redact(['reset_contact' => $this->user->email, 'password_reset_binding' => 'secret']))
        ->toBe(['reset_contact' => '[REDACTED]', 'password_reset_binding' => '[REDACTED]']);
});

it('persists wrong attempts and refuses the correct code after the attempt limit', function (): void {
    $reset = $this->action->start($this->user->tenant_id, RegistrationChannel::Email, $this->user->email, 'browser', '127.0.0.1', (string) Str::uuid());
    $code = recoveryCode();
    foreach (range(1, 5) as $attempt) {
        expect(fn () => $this->action->complete($this->user->tenant_id, $reset->id, 'browser', '000000', 'NewPassword1234'))->toThrow(DomainException::class);
        expect($reset->fresh()->attempt_count)->toBe($attempt);
    }
    expect(fn () => $this->action->complete($this->user->tenant_id, $reset->id, 'browser', $code, 'NewPassword1234'))->toThrow(DomainException::class)
        ->and($this->user->fresh()->session_version)->toBe(0);
});

it('rejects expired wrong-browser and unknown proofs', function (): void {
    $reset = $this->action->start($this->user->tenant_id, RegistrationChannel::Email, $this->user->email, 'browser', '127.0.0.1', (string) Str::uuid());
    $code = recoveryCode();
    expect(fn () => $this->action->complete($this->user->tenant_id, $reset->id, 'another-browser', $code, 'NewPassword1234'))->toThrow(DomainException::class)
        ->and(fn () => $this->action->view($this->user->tenant_id, $reset->id, 'another-browser'))->toThrow(DomainException::class)
        ->and(fn () => $this->action->complete($this->user->tenant_id, (string) Str::uuid(), 'browser', $code, 'NewPassword1234'))->toThrow(DomainException::class);
    $this->travel(11)->minutes();
    expect(fn () => $this->action->complete($this->user->tenant_id, $reset->id, 'browser', $code, 'NewPassword1234'))->toThrow(DomainException::class);
});

it('never attaches an unmatched proof to an account verified later', function (): void {
    $this->user->update(['email_verified_at' => null]);
    $reset = $this->action->start($this->user->tenant_id, RegistrationChannel::Email, $this->user->email, 'browser', '127.0.0.1', (string) Str::uuid());
    $code = recoveryCode();
    $this->user->update(['email_verified_at' => now()]);
    expect($reset->user_id)->toBeNull()
        ->and(fn () => $this->action->complete($this->user->tenant_id, $reset->id, 'browser', $code, 'NewPassword1234'))->toThrow(DomainException::class)
        ->and(Hash::check('local-password', $this->user->fresh()->password_hash))->toBeTrue();
});

it('resolves same-email identities independently and rejects cross-company proof access', function (): void {
    $other = User::query()->where('email', 'user@b.localhost')->firstOrFail();
    $other->update(['email' => $this->user->email]);
    $reset = $this->action->start($this->user->tenant_id, RegistrationChannel::Email, $this->user->email, 'browser', '127.0.0.1', (string) Str::uuid());
    $code = recoveryCode();
    expect(fn () => $this->action->complete($other->tenant_id, $reset->id, 'browser', $code, 'NewPassword1234'))->toThrow(DomainException::class);
    $this->action->complete($this->user->tenant_id, $reset->id, 'browser', $code, 'NewPassword1234');
    expect(Hash::check('local-password', $other->fresh()->password_hash))->toBeTrue();
});

it('invalidates proofs after password changes contact replacements or session revocation', function (string $change): void {
    $reset = $this->action->start($this->user->tenant_id, RegistrationChannel::Email, $this->user->email, 'browser', '127.0.0.1', (string) Str::uuid());
    $code = recoveryCode();
    if ($change === 'password') {
        app(ChangeUserPasswordAction::class)->execute($this->user->tenant_id, $this->user->id, 'local-password', 'OtherPassword123');
    }
    if ($change === 'contact') {
        $this->user->update(['email' => 'replacement@example.test']);
    }
    if ($change === 'sessions') {
        app(RevokeOtherUserSessionsAction::class)->execute($this->user->tenant_id, $this->user->id, 'local-password');
    }
    expect(fn () => $this->action->complete($this->user->tenant_id, $reset->id, 'browser', $code, 'NewPassword1234'))->toThrow(DomainException::class);
})->with(['password', 'contact', 'sessions']);

it('replays the same send intent without resending and replaces only its own browser proof', function (): void {
    $requestId = (string) Str::uuid();
    $first = $this->action->start($this->user->tenant_id, RegistrationChannel::Email, $this->user->email, 'browser', '127.0.0.1', $requestId);
    $repeat = $this->action->start($this->user->tenant_id, RegistrationChannel::Email, $this->user->email, 'browser', '127.0.0.1', $requestId);
    expect($repeat->id)->toBe($first->id);
    Mail::assertSent(UserVerificationCodeMail::class, 1);
    expect(fn () => $this->action->start($this->user->tenant_id, RegistrationChannel::Email, $this->user->email, 'browser', '127.0.0.1', (string) Str::uuid()))->toThrow(DomainException::class);
    $this->travel(61)->seconds();
    $this->action->start($this->user->tenant_id, RegistrationChannel::Email, $this->user->email, 'another-browser', '127.0.0.1', (string) Str::uuid());
    expect($first->fresh()->cancelled_at)->toBeNull();
    $this->travel(61)->seconds();
    $this->action->start($this->user->tenant_id, RegistrationChannel::Email, $this->user->email, 'browser', '127.0.0.1', (string) Str::uuid());
    expect($first->fresh()->cancelled_at)->not->toBeNull();
});

it('retains uncertain delivery and prevents blind resend until expiry', function (): void {
    $this->app->instance(EmailVerificationSender::class, new class implements EmailVerificationSender
    {
        public function isAvailable(Tenant $tenant): bool
        {
            return true;
        }

        public function sendVerificationCode(Tenant $tenant, string $destination, string $code): void
        {
            throw new EmailDeliveryUnknown;
        }

        public function sendExistingAccountNotice(Tenant $tenant, string $destination): void {}
    });
    $action = app(ResetForgottenUserPasswordAction::class);
    $reset = $action->start($this->user->tenant_id, RegistrationChannel::Email, $this->user->email, 'browser', '127.0.0.1', (string) Str::uuid());
    expect($reset->fresh()->delivery_uncertain)->toBeTrue();
    $this->travel(61)->seconds();
    expect(fn () => $action->start($this->user->tenant_id, RegistrationChannel::Email, $this->user->email, 'browser', '127.0.0.1', (string) Str::uuid()))->toThrow(DomainException::class);
});

it('requires a strong confirmed password and explicit acknowledgement then preserves admin login', function (): void {
    $this->post('http://a.localhost/admin/login', ['email' => 'owner@a.localhost', 'password' => 'local-password'])->assertRedirect();
    $this->from('http://a.localhost/forgot-password')->post('http://a.localhost/forgot-password', ['channel' => 'EMAIL', 'reset_contact' => $this->user->email, 'request_id' => (string) Str::uuid()])->assertRedirect();
    $reset = UserPasswordReset::query()->firstOrFail();
    $code = recoveryCode();
    $this->get('http://a.localhost/forgot-password/'.$reset->id)->assertOk()->assertInertia(fn ($page) => $page->where('reset.id', $reset->id)->missing('reset.user_id')->missing('reset.destination'));
    $this->post('http://a.localhost/forgot-password/'.$reset->id, ['code' => $code, 'password' => 'short', 'password_confirmation' => 'different'])->assertSessionHasErrors(['password', 'confirmed']);
    $this->post('http://a.localhost/forgot-password/'.$reset->id, ['code' => $code, 'password' => 'NewPassword1234', 'password_confirmation' => 'NewPassword1234', 'confirmed' => true])->assertRedirect('/login');
    $this->assertGuest('tenant_user')->assertAuthenticated('tenant_admin');
    $this->post('http://a.localhost/login', ['identifier' => $this->user->email, 'password' => 'NewPassword1234'])->assertRedirect('/dashboard');
});

it('blocks pre-reset sessions and allows recovery without changing suspension status', function (): void {
    $this->user->update(['status' => UserStatus::Suspended]);
    $reset = $this->action->start($this->user->tenant_id, RegistrationChannel::Email, $this->user->email, 'browser', '127.0.0.1', (string) Str::uuid());
    $this->action->complete($this->user->tenant_id, $reset->id, 'browser', recoveryCode(), 'NewPassword1234');
    expect($this->user->fresh()->status)->toBe(UserStatus::Suspended);
    $this->withSession([Auth::guard('tenant_user')->getName() => $this->user->id, 'tenant_user_session_version' => 0])->get('http://a.localhost/account/security')->assertRedirect('/login');
});

it('enforces normalized recipient rate limits and rejects closed company recovery', function (): void {
    config(['user-auth.send_limit_per_hour' => 1]);
    $this->action->start($this->user->tenant_id, RegistrationChannel::Email, $this->user->email, 'browser', '127.0.0.1', (string) Str::uuid());
    $this->travel(61)->seconds();
    expect(fn () => $this->action->start($this->user->tenant_id, RegistrationChannel::Email, strtoupper($this->user->email), 'another-browser', '127.0.0.1', (string) Str::uuid()))->toThrow(DomainException::class);
    Tenant::query()->whereKey($this->user->tenant_id)->update(['status' => TenantStatus::Closed, 'closed_at' => now()]);
    $this->get('http://a.localhost/forgot-password')->assertStatus(503);
});
