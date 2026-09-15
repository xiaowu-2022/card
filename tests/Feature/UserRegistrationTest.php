<?php

use App\Application\Promotion\PromotionMembershipAction;
use App\Application\User\CreateRegistrationChallengeAction;
use App\Application\User\RegisterUserAction;
use App\Application\User\VerifyRegistrationChallengeAction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notification\Contracts\EmailVerificationSender;
use App\Domain\Notification\Contracts\SmsVerificationSender;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantLocale;
use App\Domain\User\Enums\RegistrationChallengeStatus;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Models\RegistrationChallenge;
use App\Domain\User\Models\User;
use App\Infrastructure\Mail\LaravelEmailVerificationSender;
use App\Infrastructure\Sms\FakeSmsVerificationSender;
use App\Infrastructure\Sms\UnavailableSmsVerificationSender;
use App\Mail\ExistingUserAccountMail;
use App\Mail\UserVerificationCodeMail;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(fn () => $this->seed());

it('completes email verification before creating a tenant user', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $created = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, ' New.User+tag@Example.com ');

    expect(User::query()->where('tenant_id', $tenant->id)->where('email', 'new.user+tag@example.com')->exists())->toBeFalse()
        ->and($created->challenge->destination)->toBe('new.user+tag@example.com')
        ->and($created->challenge->code_hash)->not->toBe($created->rawCode);
    Mail::assertSent(UserVerificationCodeMail::class, 1);

    app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $created->challenge->id, $created->rawCode);
    $user = app(RegisterUserAction::class)->execute($tenant, $created->challenge->id, 'StrongPass1234', 'New User');
    expect($user->email)->toBe('new.user+tag@example.com')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->phone)->toBeNull()
        ->and($user->profile->display_name)->toBe('New User')
        ->and($user->preference->locale)->toBe('en')
        ->and($created->challenge->fresh()->consumed_at)->not->toBeNull();
});

it('normalizes and verifies phone registration through the fake sender', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $created = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Phone, '012-345 6789', 'MY');
    $sender = app(SmsVerificationSender::class);

    expect($sender)->toBeInstanceOf(FakeSmsVerificationSender::class)
        ->and($created->challenge->destination)->toBe('+60123456789')
        ->and($sender->messages()[0]['code'])->toBe($created->rawCode);
    app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $created->challenge->id, $created->rawCode);
    $user = app(RegisterUserAction::class)->execute($tenant, $created->challenge->id, 'StrongPass1234');
    expect($user->phone)->toBe('+60123456789')->and($user->phone_verified_at)->not->toBeNull()->and($user->email)->toBeNull();
});

it('rejects invalid email and phone destinations', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    expect(fn () => app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'not-email'))->toThrow(DomainException::class)
        ->and(fn () => app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Phone, '123', 'MY'))->toThrow(DomainException::class);
});

it('expires challenges and locks after five incorrect codes', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $expired = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'expired@example.test');
    $expired->challenge->update(['expires_at' => now()->subSecond()]);
    expect(fn () => app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $expired->challenge->id, $expired->rawCode))->toThrow(DomainException::class);
    expect($expired->challenge->fresh()->status)->toBe(RegistrationChallengeStatus::Expired);

    $locked = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'locked@example.test');
    foreach (range(1, 5) as $_) {
        expect(fn () => app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $locked->challenge->id, '000000'))->toThrow(DomainException::class);
    }
    expect($locked->challenge->fresh()->status)->toBe(RegistrationChallengeStatus::Locked)
        ->and($locked->challenge->fresh()->attempt_count)->toBe(5)
        ->and(AuditLog::query()->where('action', 'REGISTRATION_CHALLENGE_LOCKED')->where('resource_id', $locked->challenge->id)->exists())->toBeTrue();
});

it('cancels the previous pending code on resend', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $first = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'resend@example.test');
    $this->travel((int) config('user-auth.resend_cooldown_seconds') + 1)->seconds();
    $second = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'resend@example.test');
    expect($first->challenge->fresh()->status)->toBe(RegistrationChallengeStatus::Cancelled)
        ->and($second->challenge->status)->toBe(RegistrationChallengeStatus::Pending);
    expect(fn () => app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $first->challenge->id, $first->rawCode))->toThrow(DomainException::class);
});

it('requires verified unconsumed challenges in the correct tenant', function (): void {
    Mail::fake();
    $tenantA = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $tenantB = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $created = app(CreateRegistrationChallengeAction::class)->execute($tenantA, RegistrationChannel::Email, 'scope@example.test');

    expect(fn () => app(RegisterUserAction::class)->execute($tenantA, $created->challenge->id, 'StrongPass1234'))->toThrow(DomainException::class)
        ->and(fn () => app(VerifyRegistrationChallengeAction::class)->execute($tenantB->id, $created->challenge->id, $created->rawCode))->toThrow(DomainException::class);
    app(VerifyRegistrationChallengeAction::class)->execute($tenantA->id, $created->challenge->id, $created->rawCode);
    app(RegisterUserAction::class)->execute($tenantA, $created->challenge->id, 'StrongPass1234');
    expect(fn () => app(RegisterUserAction::class)->execute($tenantA, $created->challenge->id, 'StrongPass1234'))->toThrow(DomainException::class);
    expect(User::query()->where('tenant_id', $tenantA->id)->where('email', 'scope@example.test')->count())->toBe(1);
});

it('does not persist or audit raw verification codes', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $created = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'private@example.test');
    expect(RegistrationChallenge::query()->get()->toJson())->not->toContain($created->rawCode)
        ->and(AuditLog::query()->get()->toJson())->not->toContain($created->rawCode)
        ->and($created->challenge->toArray())->not->toHaveKey('code_hash');
});

it('normalizes existing contacts and sends a non-enumerating account notice instead of an otp', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $created = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, ' USER@A.LOCALHOST ');
    expect($created->existingAccount)->toBeTrue()
        ->and($created->challenge->status)->toBe(RegistrationChallengeStatus::Pending)
        ->and(User::query()->where('tenant_id', $tenant->id)->where('email', 'user@a.localhost')->count())->toBe(1);
    Mail::assertSent(ExistingUserAccountMail::class, 1);
    Mail::assertNotSent(UserVerificationCodeMail::class);
});

it('rate limits repeated registration attempts for an existing destination', function (): void {
    Mail::fake();
    config(['user-auth.resend_cooldown_seconds' => 0, 'user-auth.send_limit_per_hour' => 1]);
    $payload = ['channel' => 'EMAIL', 'destination' => ' USER@A.LOCALHOST ', 'invitation_code' => registrationTestInvitation()];

    $this->post('http://a.localhost/register/challenges', $payload)->assertRedirect();
    $this->post('http://a.localhost/register/challenges', $payload)
        ->assertSessionHasErrors('destination', fn (string $message) => str_contains($message, 'Too many verification requests'));

    Mail::assertSent(ExistingUserAccountMail::class, 1);
    Mail::assertNotSent(UserVerificationCodeMail::class);
});

it('rate limits verification sends per tenant destination and keeps tenants isolated', function (): void {
    Mail::fake();
    config(['user-auth.resend_cooldown_seconds' => 0, 'user-auth.send_limit_per_hour' => 2]);
    $payload = ['channel' => 'EMAIL', 'destination' => 'limits@example.test', 'invitation_code' => registrationTestInvitation()];
    $this->post('http://a.localhost/register/challenges', $payload)->assertRedirect();
    $this->post('http://a.localhost/register/challenges', $payload)->assertRedirect();
    $this->post('http://a.localhost/register/challenges', $payload)->assertSessionHasErrors('destination');
    $this->post('http://b.localhost/register/challenges', [...$payload, 'invitation_code' => registrationTestInvitation('tenant-b')])->assertRedirect();
});

it('uses normalized phone destinations in send rate-limit keys', function (): void {
    config(['user-auth.resend_cooldown_seconds' => 0, 'user-auth.send_limit_per_hour' => 1]);
    $this->post('http://a.localhost/register/challenges', ['channel' => 'PHONE', 'destination' => '+60 12-345 6789', 'invitation_code' => registrationTestInvitation()])->assertRedirect();
    $this->post('http://a.localhost/register/challenges', ['channel' => 'PHONE', 'destination' => '0123456789', 'region' => 'MY', 'invitation_code' => registrationTestInvitation()])->assertSessionHasErrors('destination');
});

it('rate limits otp verification independently of persistent challenge locking', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $created = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'verify-limit@example.test');
    $this->withSession(['registration.challenge_ids' => [$created->challenge->id]]);
    foreach (range(1, 5) as $_) {
        $this->post("http://a.localhost/register/challenges/{$created->challenge->id}/verify", ['code' => '000000'])->assertSessionHasErrors();
    }
    $this->post("http://a.localhost/register/challenges/{$created->challenge->id}/verify", ['code' => $created->rawCode])
        ->assertSessionHasErrors('code', fn (string $message) => str_contains($message, 'Too many verification attempts'));
});

it('expires an old pending challenge before creating its replacement', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $old = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'expired-resend@example.test');
    $old->challenge->update(['expires_at' => now()->subSecond()]);

    $replacement = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'expired-resend@example.test');

    expect($old->challenge->fresh()->status)->toBe(RegistrationChallengeStatus::Expired)
        ->and($replacement->challenge->status)->toBe(RegistrationChallengeStatus::Pending)
        ->and(RegistrationChallenge::query()->where('tenant_id', $tenant->id)->where('destination', 'expired-resend@example.test')->where('status', RegistrationChallengeStatus::Pending)->count())->toBe(1);
});

it('rejects the old otp after resend and accepts only the replacement otp', function (): void {
    Mail::fake();
    config(['user-auth.resend_cooldown_seconds' => 0]);
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $old = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'old-otp@example.test');
    $replacement = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'old-otp@example.test');

    expect(fn () => app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $old->challenge->id, (string) $old->rawCode))->toThrow(DomainException::class)
        ->and(fn () => app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $replacement->challenge->id, (string) $old->rawCode))->toThrow(DomainException::class);
    app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $replacement->challenge->id, (string) $replacement->rawCode);
    expect($replacement->challenge->fresh()->status)->toBe(RegistrationChallengeStatus::Verified);
});

it('reuses a valid verified registration state instead of sending another otp', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $first = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'verified-reuse@example.test');
    app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $first->challenge->id, (string) $first->rawCode);

    $reused = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'verified-reuse@example.test', null, null, [$first->challenge->id]);

    expect($reused->challenge->id)->toBe($first->challenge->id)
        ->and($reused->reusedVerified)->toBeTrue()
        ->and($reused->rawCode)->toBeNull()
        ->and(RegistrationChallenge::query()->where('tenant_id', $tenant->id)->where('destination', 'verified-reuse@example.test')->count())->toBe(1);
    Mail::assertSent(UserVerificationCodeMail::class, 1);
});

it('requires the initiating browser session before a verified challenge can complete registration', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $company = app(PromotionMembershipAction::class)->companyInvitation($tenant->id);
    $created = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'session-bound@example.test', companyInvitationId: $company->id);
    app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $created->challenge->id, (string) $created->rawCode);
    $payload = ['password' => 'StrongPass1234', 'password_confirmation' => 'StrongPass1234'];

    $this->post("http://a.localhost/register/challenges/{$created->challenge->id}/complete", $payload)->assertSessionHasErrors('form');
    expect(User::query()->where('email', 'session-bound@example.test')->exists())->toBeFalse();

    $this->withSession(['registration.challenge_ids' => [$created->challenge->id]])
        ->post("http://a.localhost/register/challenges/{$created->challenge->id}/complete", $payload)
        ->assertRedirect('/dashboard');
});

it('requires a fresh otp in another session and expires the older verified state on success', function (): void {
    Mail::fake();
    config(['user-auth.resend_cooldown_seconds' => 0]);
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $older = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'verified-superseded@example.test');
    app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $older->challenge->id, (string) $older->rawCode);

    $newer = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'verified-superseded@example.test');
    expect($newer->reusedVerified)->toBeFalse()->and($newer->rawCode)->not->toBeNull();
    app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $newer->challenge->id, (string) $newer->rawCode);

    expect($older->challenge->fresh()->status)->toBe(RegistrationChallengeStatus::Verified)
        ->and($older->challenge->fresh()->expires_at->isPast())->toBeTrue()
        ->and($newer->challenge->fresh()->status)->toBe(RegistrationChallengeStatus::Verified)
        ->and(fn () => app(RegisterUserAction::class)->execute($tenant, $older->challenge->id, 'StrongPass1234'))->toThrow(DomainException::class);
});

it('does not allow an expired verified challenge to create a user', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $created = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'verified-expired@example.test');
    app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $created->challenge->id, (string) $created->rawCode);
    $created->challenge->update(['expires_at' => now()->subSecond()]);

    expect(fn () => app(RegisterUserAction::class)->execute($tenant, $created->challenge->id, 'StrongPass1234'))->toThrow(DomainException::class)
        ->and(User::query()->where('tenant_id', $tenant->id)->where('email', 'verified-expired@example.test')->exists())->toBeFalse();
});

it('uses a current enabled tenant locale and rejects a disabled locale preference', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    TenantLocale::query()->create(['tenant_id' => $tenant->id, 'locale' => 'fr', 'enabled' => false, 'is_default' => false]);
    $created = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'locale@example.test');
    app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $created->challenge->id, (string) $created->rawCode);

    $user = app(RegisterUserAction::class)->execute($tenant, $created->challenge->id, 'StrongPass1234', null, 'fr');

    expect($user->preference->locale)->toBe('en')
        ->and($user->preference->tenant_id)->toBe($tenant->id);
});

it('maps different verified challenges for one destination to one user and a safe business error', function (): void {
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $attributes = [
        'tenant_id' => $tenant->id,
        'channel' => RegistrationChannel::Email,
        'destination' => 'registration-race@example.test',
        'code_hash' => str_repeat('a', 64),
        'status' => RegistrationChallengeStatus::Verified,
        'expires_at' => now()->addMinutes(10),
        'verified_at' => now(),
    ];
    $first = RegistrationChallenge::query()->create($attributes);
    $second = RegistrationChallenge::query()->create($attributes);

    app(RegisterUserAction::class)->execute($tenant, $first->id, 'StrongPass1234');
    try {
        app(RegisterUserAction::class)->execute($tenant, $second->id, 'StrongPass1234');
        $this->fail('The duplicate registration should fail.');
    } catch (DomainException $exception) {
        expect($exception->errorCode)->toBe('ACCOUNT_ALREADY_EXISTS');
    }

    expect(User::query()->where('tenant_id', $tenant->id)->where('email', 'registration-race@example.test')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'USER_REGISTERED')->where('resource_id', User::query()->where('email', 'registration-race@example.test')->value('id'))->count())->toBe(1);
});

it('keeps the fifth wrong attempt as the locking boundary and rejects the correct code afterward', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $created = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'attempt-boundary@example.test');
    foreach (range(1, 4) as $attempt) {
        expect(fn () => app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $created->challenge->id, '000000'))->toThrow(DomainException::class);
        expect($created->challenge->fresh()->attempt_count)->toBe($attempt)
            ->and($created->challenge->fresh()->status)->toBe(RegistrationChallengeStatus::Pending);
    }
    expect(fn () => app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $created->challenge->id, '000000'))->toThrow(DomainException::class);
    expect($created->challenge->fresh()->attempt_count)->toBe(5)
        ->and($created->challenge->fresh()->status)->toBe(RegistrationChallengeStatus::Locked)
        ->and(fn () => app(VerifyRegistrationChallengeAction::class)->execute($tenant->id, $created->challenge->id, (string) $created->rawCode))->toThrow(DomainException::class)
        ->and($created->challenge->fresh()->attempt_count)->toBe(5);
});

it('does not expose unavailable phone registration as an active channel', function (): void {
    $this->app->instance(SmsVerificationSender::class, new UnavailableSmsVerificationSender);

    $this->get('http://a.localhost/register')->assertOk()->assertInertia(fn ($page) => $page
        ->where('registration.emailAvailable', true)
        ->where('registration.phoneAvailable', false));
    $this->post('http://a.localhost/register/challenges', ['channel' => 'PHONE', 'destination' => '+60123456789', 'invitation_code' => registrationTestInvitation()])
        ->assertSessionHasErrors('form', 'Phone verification is not available in this environment.');
    expect(RegistrationChallenge::query()->where('channel', RegistrationChannel::Phone)->exists())->toBeFalse();
});

it('commits challenge state before email delivery and permits replacement after definitive rejection', function (): void {
    $this->app->instance(EmailVerificationSender::class, new class implements EmailVerificationSender
    {
        public function isAvailable(Tenant $tenant): bool
        {
            return true;
        }

        public function sendVerificationCode(Tenant $tenant, string $destination, string $code): void
        {
            throw new DomainException('EMAIL_SEND_REJECTED', 'Simulated definitive rejection.');
        }

        public function sendExistingAccountNotice(Tenant $tenant, string $destination): void
        {
            throw new DomainException('EMAIL_SEND_REJECTED', 'Simulated definitive rejection.');
        }
    });
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();

    expect(fn () => app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'mail-failure@example.test'))->toThrow(RuntimeException::class);
    $failedDeliveryChallenge = RegistrationChallenge::query()->where('tenant_id', $tenant->id)->where('destination', 'mail-failure@example.test')->firstOrFail();
    expect($failedDeliveryChallenge->status)->toBe(RegistrationChallengeStatus::Pending);

    config(['user-auth.resend_cooldown_seconds' => 0]);
    Mail::fake();
    $this->app->instance(EmailVerificationSender::class, app(LaravelEmailVerificationSender::class));
    $replacement = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'mail-failure@example.test');
    expect($failedDeliveryChallenge->fresh()->status)->toBe(RegistrationChallengeStatus::Cancelled)
        ->and($replacement->challenge->status)->toBe(RegistrationChallengeStatus::Pending);
});

it('returns the same safe challenge message for unknown wrong-tenant and cancelled ids', function (): void {
    Mail::fake();
    config(['user-auth.resend_cooldown_seconds' => 0]);
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $cancelled = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'enumeration@example.test');
    app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'enumeration@example.test');
    $unknownId = (string) str()->uuid();
    $this->withSession(['registration.challenge_ids' => [$unknownId, $cancelled->challenge->id]]);

    foreach ([
        "http://a.localhost/register/challenges/{$unknownId}",
        "http://b.localhost/register/challenges/{$cancelled->challenge->id}",
        "http://a.localhost/register/challenges/{$cancelled->challenge->id}",
    ] as $url) {
        $this->get($url)->assertStatus(422)->assertSee('This verification request is invalid or expired.');
    }
});

it('hashes pii in registration rate-limit keys and never includes otp values', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $destination = 'rate-key@example.test';
    $this->post('http://a.localhost/register/challenges', ['channel' => 'EMAIL', 'destination' => $destination, 'invitation_code' => registrationTestInvitation()])->assertRedirect();
    $created = RegistrationChallenge::query()->where('tenant_id', $tenant->id)->where('destination', $destination)->firstOrFail();
    $rawCode = Mail::sent(UserVerificationCodeMail::class)->first()->code;

    $hashedKey = 'registration-send|destination|'.$tenant->id.'|'.hash('sha256', $destination);
    expect(RateLimiter::attempts($hashedKey))->toBe(1)
        ->and(RateLimiter::attempts('registration-send|destination|'.$tenant->id.'|'.$destination))->toBe(0)
        ->and(RateLimiter::attempts('registration-send|destination|'.$tenant->id.'|'.$rawCode))->toBe(0)
        ->and(RateLimiter::attempts('registration-send|destination|'.$tenant->id.'|'.$created->code_hash))->toBe(0);
});
