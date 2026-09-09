<?php

use App\Application\User\CreateRegistrationChallengeAction;
use App\Application\User\RegisterUserAction;
use App\Application\User\VerifyRegistrationChallengeAction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notification\Contracts\SmsVerificationSender;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\RegistrationChallengeStatus;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Models\RegistrationChallenge;
use App\Domain\User\Models\User;
use App\Infrastructure\Sms\FakeSmsVerificationSender;
use App\Mail\ExistingUserAccountMail;
use App\Mail\UserVerificationCodeMail;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Mail;

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
    $payload = ['channel' => 'EMAIL', 'destination' => ' USER@A.LOCALHOST '];

    $this->post('http://a.localhost/register/challenges', $payload)->assertRedirect();
    $this->post('http://a.localhost/register/challenges', $payload)
        ->assertSessionHasErrors('destination', fn (string $message) => str_contains($message, 'Too many verification requests'));

    Mail::assertSent(ExistingUserAccountMail::class, 1);
    Mail::assertNotSent(UserVerificationCodeMail::class);
});

it('rate limits verification sends per tenant destination and keeps tenants isolated', function (): void {
    Mail::fake();
    config(['user-auth.resend_cooldown_seconds' => 0, 'user-auth.send_limit_per_hour' => 2]);
    $payload = ['channel' => 'EMAIL', 'destination' => 'limits@example.test'];
    $this->post('http://a.localhost/register/challenges', $payload)->assertRedirect();
    $this->post('http://a.localhost/register/challenges', $payload)->assertRedirect();
    $this->post('http://a.localhost/register/challenges', $payload)->assertSessionHasErrors('destination');
    $this->post('http://b.localhost/register/challenges', $payload)->assertRedirect();
});

it('uses normalized phone destinations in send rate-limit keys', function (): void {
    config(['user-auth.resend_cooldown_seconds' => 0, 'user-auth.send_limit_per_hour' => 1]);
    $this->post('http://a.localhost/register/challenges', ['channel' => 'PHONE', 'destination' => '+60 12-345 6789'])->assertRedirect();
    $this->post('http://a.localhost/register/challenges', ['channel' => 'PHONE', 'destination' => '0123456789', 'region' => 'MY'])->assertSessionHasErrors('destination');
});

it('rate limits otp verification independently of persistent challenge locking', function (): void {
    Mail::fake();
    $tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $created = app(CreateRegistrationChallengeAction::class)->execute($tenant, RegistrationChannel::Email, 'verify-limit@example.test');
    foreach (range(1, 5) as $_) {
        $this->post("http://a.localhost/register/challenges/{$created->challenge->id}/verify", ['code' => '000000'])->assertSessionHasErrors();
    }
    $this->post("http://a.localhost/register/challenges/{$created->challenge->id}/verify", ['code' => $created->rawCode])
        ->assertSessionHasErrors('code', fn (string $message) => str_contains($message, 'Too many verification attempts'));
});
