<?php

use App\Application\User\ChangeUserContactAction;
use App\Application\User\CreateRegistrationChallengeAction;
use App\Application\User\ResetForgottenUserPasswordAction;
use App\Domain\Notification\Models\PlatformEmailProfile;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Models\User;
use App\Mail\UserVerificationCodeMail;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    Mail::fake();
    $this->tenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::query()->where('tenant_id', $this->tenant->id)->where('email', 'user@a.localhost')->firstOrFail();
    $profile = PlatformEmailProfile::query()->create(['name' => 'Quota', 'configuration_version' => (string) Str::uuid(),
        'enabled' => false, 'from_address' => 'sender@example.test', 'from_name' => 'Test', 'daily_recipient_limit' => 2]);
    bindNotificationTestProfile($this->tenant, 'email', $profile->id);
    $this->send = function (string $flow, string $email = 'quota@example.test') {
        return match ($flow) {
            'register' => app(CreateRegistrationChallengeAction::class)->execute($this->tenant, RegistrationChannel::Email, $email),
            'contact' => app(ChangeUserContactAction::class)->start($this->tenant->id, $this->user->id, RegistrationChannel::Email, $email, 'local-password', 'browser', (string) Str::uuid()),
            'reset' => app(ResetForgottenUserPasswordAction::class)->start($this->tenant->id, RegistrationChannel::Email, $email, 'browser', '127.0.0.1', (string) Str::uuid()),
        };
    };
});

it('shares one daily recipient cap regardless of the order of the three entry points', function (array $flows): void {
    ($this->send)($flows[0], ' QUOTA@EXAMPLE.TEST ');
    $this->travel(61)->seconds();
    ($this->send)($flows[1]);
    $this->travel(61)->seconds();
    expect(fn () => ($this->send)($flows[2]))->toThrow(DomainException::class, 'daily email verification limit');
    Mail::assertSent(UserVerificationCodeMail::class, 2);
})->with([
    [['register', 'contact', 'reset']], [['register', 'reset', 'contact']],
    [['contact', 'register', 'reset']], [['contact', 'reset', 'register']],
    [['reset', 'register', 'contact']], [['reset', 'contact', 'register']],
]);

it('resets the combined cap at company midnight and keeps companies independent', function (): void {
    $this->tenant->update(['timezone' => 'Asia/Kuala_Lumpur']);
    $this->travelTo(now()->setTimezone('Asia/Kuala_Lumpur')->setTime(23, 57));
    ($this->send)('contact');
    $this->travel(61)->seconds();
    ($this->send)('reset');
    $this->travel(61)->seconds();
    expect(fn () => ($this->send)('register'))->toThrow(DomainException::class, 'daily email verification limit');
    $other = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    app(CreateRegistrationChallengeAction::class)->execute($other, RegistrationChannel::Email, 'quota@example.test');
    $this->travel(61)->seconds();
    ($this->send)('register');
    Mail::assertSent(UserVerificationCodeMail::class, 4);
});

it('does not consume another slot for an idempotent reset request', function (): void {
    $id = (string) Str::uuid();
    $action = app(ResetForgottenUserPasswordAction::class);
    for ($i = 0; $i < 2; $i++) {
        $action->start($this->tenant->id, RegistrationChannel::Email, 'quota@example.test', 'browser', '127.0.0.1', $id);
    }
    ($this->send)('register');
    Mail::assertSent(UserVerificationCodeMail::class, 2);
});
