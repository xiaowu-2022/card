<?php

use App\Application\User\ChangeUserContactAction;
use App\Application\User\ChangeUserPasswordAction;
use App\Application\User\UpdateUserNameAction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notification\Contracts\EmailVerificationSender;
use App\Domain\Notification\Contracts\SmsVerificationSender;
use App\Domain\Notification\Exceptions\EmailDeliveryUnknown;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserContactChange;
use App\Infrastructure\Sms\UnavailableSmsVerificationSender;
use App\Mail\UserVerificationCodeMail;
use App\Support\Errors\DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    Mail::fake();
    $this->user = User::query()->where('email', 'user@a.localhost')->firstOrFail();
    $this->action = app(ChangeUserContactAction::class);
});

function accountChangeCode(): string
{
    return Mail::sent(UserVerificationCodeMail::class)->last()->code;
}

it('updates only the display name with company ownership and validates length', function (): void {
    $before = $this->user->fresh()->getAttributes();
    $profile = $this->user->profile->getAttributes();
    $this->actingAs($this->user, 'tenant_user')->post('http://a.localhost/account/information/name', ['display_name' => '  My Name  '])->assertRedirect();
    expect($this->user->fresh()->getAttributes())->toBe($before)
        ->and($this->user->profile->fresh()->display_name)->toBe('My Name');
    foreach ($profile as $key => $value) {
        if (! in_array($key, ['display_name', 'updated_at'])) {
            expect($this->user->profile->fresh()->getRawOriginal($key))->toBe($value);
        }
    }
    expect(fn () => app(UpdateUserNameAction::class)->execute($this->user->tenant_id, $this->user->id, ' '))->toThrow(DomainException::class);
});

it('requires password before sending and never updates a contact before proof', function (): void {
    expect(fn () => $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'new@example.test', 'wrong', 'browser', (string) Str::uuid()))->toThrow(DomainException::class);
    Mail::assertNothingSent();
    expect(UserContactChange::query()->count())->toBe(0);
    $change = $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, ' NEW@example.test ', 'local-password', 'browser', (string) Str::uuid());
    expect($this->user->fresh()->email)->toBe('user@a.localhost')
        ->and($change->destination)->toBe('new@example.test')
        ->and($change->getRawOriginal('destination'))->not->toContain('new@example.test')
        ->and(json_encode($change->toArray()))->not->toContain(accountChangeCode(), 'new@example.test', $change->code_hash, $change->session_hash);
});

it('replaces a verified email atomically once without touching money or identity', function (): void {
    $before = $this->user->getAttributes();
    $ledgerCount = DB::table('ledger_entries')->count();
    $change = $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'new@example.test', 'local-password', 'browser', (string) Str::uuid());
    $code = accountChangeCode();
    $this->action->complete($this->user->tenant_id, $this->user->id, $change->id, 'browser', $code);
    $this->action->complete($this->user->tenant_id, $this->user->id, $change->id, 'browser', $code);
    expect($this->user->fresh()->email)->toBe('new@example.test')
        ->and($this->user->fresh()->email_verified_at)->not->toBeNull()
        ->and($change->fresh()->consumed_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'USER_CONTACT_CHANGED')->count())->toBe(1)
        ->and(DB::table('ledger_entries')->count())->toBe($ledgerCount);
    foreach (['id', 'tenant_id', 'account_id', 'phone', 'password_hash', 'status'] as $key) {
        expect($this->user->fresh()->getRawOriginal($key))->toBe($before[$key]);
    }
    expect(AuditLog::query()->where('action', 'like', 'USER_CONTACT%')->get()->toJson())->not->toContain($code, 'new@example.test', 'local-password');
});

it('binds a normalized phone through the company SMS sender', function (): void {
    $change = $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Phone, '+60 12 345 6789', 'local-password', 'browser', (string) Str::uuid());
    $sent = app(SmsVerificationSender::class)->messages();
    Mail::assertNothingSent();
    expect($this->user->fresh()->phone)->toBeNull();
    expect(fn () => $this->action->complete($this->user->tenant_id, $this->user->id, $change->id, 'browser', '000000'))->toThrow(DomainException::class);
    expect($this->user->fresh()->phone)->toBeNull();
    expect($sent[0]['tenant_id'])->toBe($this->user->tenant_id)->and($sent[0]['destination'])->toBe('+60123456789');
    $this->action->complete($this->user->tenant_id, $this->user->id, $change->id, 'browser', $sent[0]['code']);
    expect($this->user->fresh()->phone)->toBe('+60123456789')->and($this->user->fresh()->phone_verified_at)->not->toBeNull();
});

it('persists wrong attempts and rejects correct proof after expiry or attempt exhaustion', function (): void {
    $change = $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'new@example.test', 'local-password', 'browser', (string) Str::uuid());
    $code = accountChangeCode();
    foreach (range(1, 5) as $attempt) {
        expect(fn () => $this->action->complete($this->user->tenant_id, $this->user->id, $change->id, 'browser', '000000'))->toThrow(DomainException::class);
        expect($change->fresh()->attempt_count)->toBe($attempt);
    }
    expect(fn () => $this->action->complete($this->user->tenant_id, $this->user->id, $change->id, 'browser', $code))->toThrow(DomainException::class);
    $this->travel(61)->seconds();
    $fresh = $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'new@example.test', 'local-password', 'browser', (string) Str::uuid());
    $code = accountChangeCode();
    $this->travel(11)->minutes();
    expect(fn () => $this->action->complete($this->user->tenant_id, $this->user->id, $fresh->id, 'browser', $code))->toThrow(DomainException::class);
});

it('rejects wrong browser and invalidates a proof after password change', function (): void {
    $change = $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'new@example.test', 'local-password', 'browser', (string) Str::uuid());
    $code = accountChangeCode();
    expect(fn () => $this->action->complete($this->user->tenant_id, $this->user->id, $change->id, 'different-browser', $code))->toThrow(DomainException::class);
    app(ChangeUserPasswordAction::class)->execute($this->user->tenant_id, $this->user->id, 'local-password', 'AnotherPassword123');
    expect(fn () => $this->action->complete($this->user->tenant_id, $this->user->id, $change->id, 'browser', $code))->toThrow(DomainException::class);
});

it('scopes uniqueness and ownership to the company and checks uniqueness again on completion', function (): void {
    $other = User::query()->where('email', 'user@b.localhost')->firstOrFail();
    $change = $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, $other->email, 'local-password', 'browser', (string) Str::uuid());
    $code = accountChangeCode();
    expect(fn () => $this->action->complete($other->tenant_id, $other->id, $change->id, 'browser', $code))->toThrow(ModelNotFoundException::class);
    $copy = $other->replicate(['account_id']);
    $copy->tenant_id = $this->user->tenant_id;
    $copy->save();
    expect(fn () => $this->action->complete($this->user->tenant_id, $this->user->id, $change->id, 'browser', $code))->toThrow(DomainException::class)
        ->and($change->fresh()->consumed_at)->toBeNull();
});

it('reuses send intent without resending and cancels older proofs only after cooldown', function (): void {
    $requestId = (string) Str::uuid();
    $change = $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'new@example.test', 'local-password', 'browser', $requestId);
    $repeat = $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'new@example.test', 'local-password', 'browser', $requestId);
    expect($repeat->id)->toBe($change->id);
    Mail::assertSent(UserVerificationCodeMail::class, 1);
    expect(fn () => $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'other@example.test', 'local-password', 'browser', (string) Str::uuid()))->toThrow(DomainException::class);
    $this->travel(61)->seconds();
    $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'other@example.test', 'local-password', 'browser', (string) Str::uuid());
    expect($change->fresh()->cancelled_at)->not->toBeNull();
});

it('preserves uncertain sends and does not blindly resend', function (): void {
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
    $action = app(ChangeUserContactAction::class);
    $change = $action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'new@example.test', 'local-password', 'browser', (string) Str::uuid());
    expect($change->fresh()->delivery_uncertain)->toBeTrue();
    $this->travel(61)->seconds();
    expect(fn () => $action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'new@example.test', 'local-password', 'browser', (string) Str::uuid()))->toThrow(DomainException::class);
});

it('requires explicit confirmation and returns only masked challenge props', function (): void {
    $this->actingAs($this->user, 'tenant_user')->from('http://a.localhost/account/security')
        ->post('http://a.localhost/account/information/contacts', ['channel' => 'EMAIL', 'new_contact' => 'new@example.test', 'current_password' => 'local-password', 'request_id' => (string) Str::uuid()])->assertRedirect();
    $change = UserContactChange::query()->firstOrFail();
    $code = accountChangeCode();
    $this->get('http://a.localhost/account/security')->assertOk()->assertInertia(fn ($page) => $page
        ->where('information.challenge.destination', 'n***@example.test')->missing('information.challenge.code_hash'));
    $this->post("http://a.localhost/account/information/contacts/{$change->id}/verify", ['code' => $code])->assertSessionHasErrors('confirmed');
    $this->post("http://a.localhost/account/information/contacts/{$change->id}/verify", ['code' => $code, 'confirmed' => true])->assertRedirect();
    expect($this->user->fresh()->email)->toBe('new@example.test');
});

it('keeps suspended accounts restricted to the original password flow', function (): void {
    $this->user->update(['status' => UserStatus::Suspended]);
    $this->actingAs($this->user, 'tenant_user')->get('http://a.localhost/account/security')->assertOk()->assertInertia(fn ($page) => $page->where('information.canEdit', false));
    $this->post('http://a.localhost/account/information/name', ['display_name' => 'Name'])->assertRedirect('/account/restricted');
    expect(fn () => $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'new@example.test', 'local-password', 'browser', (string) Str::uuid()))->toThrow(DomainException::class);
});

it('rejects unavailable transport and terminal send replays without changing contacts', function (): void {
    $this->app->instance(SmsVerificationSender::class, new UnavailableSmsVerificationSender);
    expect(fn () => app(ChangeUserContactAction::class)->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Phone, '+60123456789', 'local-password', 'browser', (string) Str::uuid()))->toThrow(DomainException::class);
    expect(UserContactChange::query()->count())->toBe(0);
    $requestId = (string) Str::uuid();
    $change = $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'new@example.test', 'local-password', 'browser', $requestId);
    $this->travel(11)->minutes();
    expect(fn () => $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'new@example.test', 'local-password', 'browser', $requestId))->toThrow(DomainException::class);
    Mail::assertSent(UserVerificationCodeMail::class, 1);
});

it('rejects another account in the same company and accepts only the new login email after completion', function (): void {
    $other = $this->user->replicate(['account_id']);
    $other->email = 'second@example.test';
    $other->save();
    $change = $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'new@example.test', 'local-password', 'browser', (string) Str::uuid());
    $code = accountChangeCode();
    expect(fn () => $this->action->complete($this->user->tenant_id, $other->id, $change->id, 'browser', $code))->toThrow(ModelNotFoundException::class);
    $this->action->complete($this->user->tenant_id, $this->user->id, $change->id, 'browser', $code);
    $this->post('http://a.localhost/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])->assertSessionHasErrors('identifier');
    $this->post('http://a.localhost/login', ['identifier' => 'new@example.test', 'password' => 'local-password'])->assertRedirect('/dashboard');
});

it('never restores an older contact when replaying a consumed proof', function (): void {
    $first = $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'first@example.test', 'local-password', 'browser', (string) Str::uuid());
    $firstCode = accountChangeCode();
    $this->action->complete($this->user->tenant_id, $this->user->id, $first->id, 'browser', $firstCode);
    $this->travel(61)->seconds();
    $second = $this->action->start($this->user->tenant_id, $this->user->id, RegistrationChannel::Email, 'second@example.test', 'local-password', 'browser', (string) Str::uuid());
    $this->action->complete($this->user->tenant_id, $this->user->id, $second->id, 'browser', accountChangeCode());
    $this->action->complete($this->user->tenant_id, $this->user->id, $first->id, 'browser', $firstCode);
    expect($this->user->fresh()->email)->toBe('second@example.test')
        ->and(AuditLog::query()->where('action', 'USER_CONTACT_CHANGED')->count())->toBe(2);
});
