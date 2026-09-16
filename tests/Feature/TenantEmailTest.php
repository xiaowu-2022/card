<?php

use App\Application\Notification\SendTenantTestEmailAction;
use App\Application\User\CreateRegistrationChallengeAction;
use App\Application\User\VerifyRegistrationChallengeAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notification\Contracts\CompanyEmailTransport;
use App\Domain\Notification\Contracts\EmailVerificationSender;
use App\Domain\Notification\DTOs\EmailConnection;
use App\Domain\Notification\Exceptions\EmailDeliveryUnknown;
use App\Domain\Notification\Models\PlatformEmailProfile;
use App\Domain\Notification\Models\TenantEmailTestRequest;
use App\Domain\Notification\Services\TenantEmailPolicy;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\RegistrationChallengeStatus;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Models\RegistrationChallenge;
use App\Infrastructure\Mail\ProtonEmailVerificationSender;
use App\Infrastructure\Mail\ProtonSmtpTransport;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

final class RecordingCompanyEmailTransport implements CompanyEmailTransport
{
    public array $messages = [];

    public string $outcome = 'ACCEPTED';

    public function send(EmailConnection $connection, string $destination, string $subject, string $html): void
    {
        $this->messages[] = compact('connection', 'destination', 'subject', 'html') + ['transactionLevel' => DB::transactionLevel()];
        if ($this->outcome === 'UNKNOWN') {
            throw new EmailDeliveryUnknown;
        }
        if ($this->outcome === 'REJECTED') {
            throw new DomainException('EMAIL_SEND_REJECTED', 'Unable to send email. Check the saved SMTP settings or contact support.', 503);
        }
    }
}

beforeEach(function (): void {
    $this->seed();
    config(['inertia.ssr.enabled' => false]);
    Cache::flush();
    Mail::fake();
    $this->emailTenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->emailOther = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $this->emailAdmin = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->emailTransport = new RecordingCompanyEmailTransport;
    $this->app->instance(CompanyEmailTransport::class, $this->emailTransport);
    $this->app->bind(EmailVerificationSender::class, ProtonEmailVerificationSender::class);
});

function emailSettingsPayload(array $overrides = []): array
{
    return [...['name' => 'Default Email', 'enabled' => true, 'from_address' => 'sender@company-a.example', 'from_name' => 'Company A',
        'smtp_token' => 'testing-only-token-A', 'daily_recipient_limit' => 10], ...$overrides];
}

function configureCompanyEmail(Tenant $tenant, array $overrides = []): PlatformEmailProfile
{
    $data = emailSettingsPayload($overrides);
    unset($data['current_password'], $data['name']);

    $profile = PlatformEmailProfile::query()->create(['name' => $tenant->name, 'configuration_version' => (string) Str::uuid(), ...$data]);
    bindNotificationTestProfile($tenant, 'email', $profile->id);

    return $profile;
}

it('saves company SMTP with encrypted token and safe props audit and no send', function (): void {
    $other = configureCompanyEmail($this->emailOther, ['smtp_token' => 'testing-only-token-B']);
    $this->actingAs($this->emailAdmin, 'platform_admin')->post(notificationProfileUrl('email', $this->emailTenant), emailSettingsPayload())
        ->assertRedirect('/platform/settings/email')->assertSessionHasNoErrors();
    bindNotificationTestProfile($this->emailTenant, 'email', PlatformEmailProfile::query()->where('name', 'Default Email')->sole()->id);
    $this->actingAs(AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail(), 'tenant_admin');
    $setting = app(TenantEmailPolicy::class)->settings($this->emailTenant->id);
    expect($setting->smtp_token)->toBe('testing-only-token-A')->and($setting->getRawOriginal('smtp_token'))->not->toContain('testing-only-token-A')
        ->and($setting->toArray())->not->toHaveKey('smtp_token')->and($other->fresh()->smtp_token)->toBe('testing-only-token-B');
    $response = $this->get('http://a.localhost/admin/settings/email')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('tenant-admin/Settings')->where('settings.email.tokenConfigured', true)->where('settings.email.fromAddress', 'sender@company-a.example')
        ->missing('settings.email.smtp_token'));
    expect($response->getContent())->not->toContain('testing-only-token-A', $setting->getRawOriginal('smtp_token'));
    expect(AuditLog::query()->whereNull('tenant_id')->where('action', 'PLATFORM_EMAIL_PROFILE_SAVED')->sole()->toJson())->not->toContain('testing-only-token-A', 'sender@company-a.example');
    $this->get('http://a.localhost/admin/settings/sms')->assertInertia(fn (Assert $page) => $page->missing('settings.email'));
    expect($this->emailTransport->messages)->toBe([]);
    Mail::assertNothingSent();
});

it('preserves blank tokens requires replacement on sender change and supports disable', function (): void {
    $setting = configureCompanyEmail($this->emailTenant);
    $this->actingAs($this->emailAdmin, 'platform_admin');
    $this->post(notificationProfileUrl('email', $this->emailTenant), emailSettingsPayload(['smtp_token' => '', 'from_name' => 'New name']))->assertSessionHasNoErrors();
    expect($setting->fresh()->smtp_token)->toBe('testing-only-token-A')->and($setting->fresh()->from_name)->toBe('New name');
    $this->post(notificationProfileUrl('email', $this->emailTenant), emailSettingsPayload(['smtp_token' => '', 'from_address' => 'other@company-a.example']))->assertSessionHasErrors('form');
    $version = $setting->fresh()->configuration_version;
    $this->post(notificationProfileUrl('email', $this->emailTenant), emailSettingsPayload())->assertSessionHasNoErrors();
    expect($setting->fresh()->configuration_version)->toBe($version); // Same token cannot bypass UNKNOWN.
    $this->post(notificationProfileUrl('email', $this->emailTenant), emailSettingsPayload(['enabled' => false, 'smtp_token' => '']))->assertSessionHasNoErrors();
    expect($setting->fresh()->enabled)->toBeFalse();
});

it('rotates the token for a new SMTP address without mutating shared mail configuration', function (): void {
    $setting = configureCompanyEmail($this->emailTenant);
    $global = config('mail');
    $this->actingAs($this->emailAdmin, 'platform_admin')->post(notificationProfileUrl('email', $this->emailTenant), emailSettingsPayload(['from_address' => 'new@company-a.example', 'smtp_token' => 'testing-new-token']))->assertSessionHasNoErrors();
    expect($setting->fresh()->configuration_version)->not->toBe($setting->configuration_version)
        ->and($setting->fresh()->from_address)->toBe('new@company-a.example')->and(config('mail'))->toBe($global);
});

it('keeps company credential writes forbidden and rejects platform tenant overrides', function (): void {
    $companyOwner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($companyOwner, 'tenant_admin')->get('http://b.localhost/admin/settings/email')->assertForbidden();
    $this->post('http://a.localhost/admin/settings/email', emailSettingsPayload())->assertForbidden();
    $this->actingAs($this->emailAdmin, 'platform_admin')->post(notificationProfileUrl('email', $this->emailTenant), emailSettingsPayload(['tenant_id' => $this->emailOther->id]))->assertSessionHasErrors('tenant_id');
    expect(PlatformEmailProfile::query()->count())->toBe(0);
});

it('validates settings without flashing tokens or passwords', function (array $override, string $field): void {
    $this->actingAs($this->emailAdmin, 'platform_admin')->post(notificationProfileUrl('email', $this->emailTenant), emailSettingsPayload($override))
        ->assertSessionHasErrors($field)->assertSessionMissing('_old_input.smtp_token')->assertSessionMissing('_old_input.current_password');
    expect(PlatformEmailProfile::query()->count())->toBe(0)->and($this->emailTransport->messages)->toBe([]);
})->with([

    [['from_address' => '1111'], 'from_address'],
    [['from_name' => "unsafe\r\nname"], 'from_name'],
    [['daily_recipient_limit' => -1], 'daily_recipient_limit'],
    [['daily_recipient_limit' => 1.5], 'daily_recipient_limit'],
    [['smtp_token' => "unsafe\nvalue"], 'smtp_token'],
]);

it('fails closed when no token is configured and exposes only this company email availability', function (): void {
    $this->actingAs($this->emailAdmin, 'platform_admin')->post(notificationProfileUrl('email', $this->emailTenant), emailSettingsPayload(['smtp_token' => '']))->assertSessionHasErrors('form');
    $this->get('http://a.localhost/register')->assertInertia(fn (Assert $page) => $page->where('registration.emailAvailable', false));
    $setting = configureCompanyEmail($this->emailTenant);
    $this->get('http://a.localhost/register')->assertInertia(fn (Assert $page) => $page->where('registration.emailAvailable', true));
    $this->get('http://b.localhost/register')->assertInertia(fn (Assert $page) => $page->where('registration.emailAvailable', false));
    $setting->update(['enabled' => false]);
    expect(fn () => app(CreateRegistrationChallengeAction::class)->execute($this->emailTenant, RegistrationChannel::Email, 'user@example.test'))->toThrow(DomainException::class);
    expect($this->emailTransport->messages)->toBe([]);
});

it('uses isolated company credentials for OTP and account notices outside the challenge transaction', function (): void {
    configureCompanyEmail($this->emailTenant);
    configureCompanyEmail($this->emailOther, ['from_address' => 'sender@company-b.example', 'smtp_token' => 'testing-only-token-B']);
    $level = DB::transactionLevel();
    $created = app(CreateRegistrationChallengeAction::class)->execute($this->emailTenant, RegistrationChannel::Email, 'recipient@example.test');
    app(EmailVerificationSender::class)->sendExistingAccountNotice($this->emailOther, 'other@example.test');
    expect($this->emailTransport->messages[0]['connection']->token)->toBe('testing-only-token-A')
        ->and($this->emailTransport->messages[0]['connection']->fromAddress)->toBe('sender@company-a.example')
        ->and($this->emailTransport->messages[0]['html'])->toContain($created->rawCode)
        ->and($this->emailTransport->messages[0]['transactionLevel'])->toBe($level)
        ->and($this->emailTransport->messages[1]['connection']->token)->toBe('testing-only-token-B')
        ->and($created->challenge->code_hash)->not->toBe($created->rawCode)
        ->and($created->challenge->fresh()->email_delivery_uncertain)->toBeFalse();
    Mail::assertNothingSent();
});

it('preserves an uncertain OTP challenge and never resends it while live', function (): void {
    configureCompanyEmail($this->emailTenant);
    $this->emailTransport->outcome = 'UNKNOWN';
    $action = app(CreateRegistrationChallengeAction::class);
    $created = $action->execute($this->emailTenant, RegistrationChannel::Email, 'recipient@example.test');
    expect($created->deliveryUncertain)->toBeTrue();
    $this->travel(61)->seconds();
    $reopen = $action->execute($this->emailTenant, RegistrationChannel::Email, 'recipient@example.test', reusableChallengeIds: [$created->challenge->id]);
    expect($reopen->challenge->id)->toBe($created->challenge->id)->and($reopen->rawCode)->toBeNull();
    expect(fn () => $action->execute($this->emailTenant, RegistrationChannel::Email, 'recipient@example.test'))->toThrow(DomainException::class);
    app(VerifyRegistrationChallengeAction::class)->execute($this->emailTenant->id, $created->challenge->id, $created->rawCode);
    expect($created->challenge->fresh()->status)->toBe(RegistrationChallengeStatus::Verified)->and($this->emailTransport->messages)->toHaveCount(1);
});

it('limits daily recipient sends on company local dates while retaining failed attempts', function (): void {
    $this->emailTenant->update(['timezone' => 'Asia/Kuala_Lumpur']);
    configureCompanyEmail($this->emailTenant, ['daily_recipient_limit' => 1]);
    $this->travelTo(now()->setTimezone('Asia/Kuala_Lumpur')->setTime(23, 58));
    $this->emailTransport->outcome = 'REJECTED';
    $action = app(CreateRegistrationChallengeAction::class);
    expect(fn () => $action->execute($this->emailTenant, RegistrationChannel::Email, 'recipient@example.test'))->toThrow(DomainException::class);
    $this->travel(61)->seconds();
    expect(fn () => $action->execute($this->emailTenant, RegistrationChannel::Email, 'recipient@example.test'))->toThrow(DomainException::class, 'daily email verification limit');
    $this->travel(61)->seconds();
    $this->emailTransport->outcome = 'ACCEPTED';
    expect($action->execute($this->emailTenant, RegistrationChannel::Email, 'recipient@example.test')->deliveryUncertain)->toBeFalse();
    expect($this->emailTransport->messages)->toHaveCount(2);
});

it('allows zero daily limit without changing the hourly security policy', function (): void {
    configureCompanyEmail($this->emailTenant, ['daily_recipient_limit' => 0]);
    $limit = config('user-auth.send_limit_per_hour');
    for ($i = 0; $i < 3; $i++) {
        app(CreateRegistrationChallengeAction::class)->execute($this->emailTenant, RegistrationChannel::Email, 'recipient@example.test');
        $this->travel(61)->seconds();
    }
    expect($this->emailTransport->messages)->toHaveCount(3)->and(config('user-auth.send_limit_per_hour'))->toBe($limit);
});

it('sends a test once using persisted intent and saved company settings without storing recipient or token', function (): void {
    configureCompanyEmail($this->emailTenant);
    $requestId = (string) Str::uuid();
    $action = app(SendTenantTestEmailAction::class);
    $level = DB::transactionLevel();
    expect($action->execute($this->emailTenant, $requestId, 'tester@example.test', $this->emailAdmin))->toBe('ACCEPTED')
        ->and($action->execute($this->emailTenant, $requestId, 'tester@example.test', $this->emailAdmin))->toBe('ACCEPTED')
        ->and($this->emailTransport->messages)->toHaveCount(1)->and($this->emailTransport->messages[0]['transactionLevel'])->toBe($level);
    $record = TenantEmailTestRequest::query()->where('tenant_id', $this->emailTenant->id)->sole();
    expect(json_encode($record->getRawOriginal()))->not->toContain('tester@example.test', 'testing-only-token-A')
        ->and(RegistrationChallenge::query()->count())->toBe(0);
    expect(fn () => $action->execute($this->emailTenant, $requestId, 'different@example.test', $this->emailAdmin))->toThrow(DomainException::class);
});

it('does not allow a new UUID to bypass an unconfirmed test or test-request cooldown', function (): void {
    configureCompanyEmail($this->emailTenant);
    $this->emailTransport->outcome = 'UNKNOWN';
    $action = app(SendTenantTestEmailAction::class);
    $id = (string) Str::uuid();
    expect($action->execute($this->emailTenant, $id, 'tester@example.test', $this->emailAdmin))->toBe('UNKNOWN');
    expect(fn () => $action->execute($this->emailTenant, (string) Str::uuid(), 'other@example.test', $this->emailAdmin))->toThrow(DomainException::class, 'Please wait');
    $this->travel(61)->seconds();
    expect(fn () => $action->execute($this->emailTenant, (string) Str::uuid(), 'tester@example.test', $this->emailAdmin))->toThrow(DomainException::class, 'unconfirmed');
    expect($action->execute($this->emailTenant, $id, 'tester@example.test', $this->emailAdmin))->toBe('UNKNOWN');
    expect($this->emailTransport->messages)->toHaveCount(1);
});

it('uses the platform session and requires saved enabled settings for test emails', function (): void {
    $this->actingAs($this->emailAdmin, 'platform_admin');
    $data = ['request_id' => (string) Str::uuid(), 'test_email' => 'tester@example.test'];
    $this->post('http://admin.localhost/platform/tenants/'.$this->emailTenant->id.'/configuration/settings/email/test', $data)->assertSessionHasErrors('form');
    configureCompanyEmail($this->emailTenant);
    $this->post('http://admin.localhost/platform/tenants/'.$this->emailTenant->id.'/configuration/settings/email/test', $data)->assertRedirect('/platform/tenants/'.$this->emailTenant->id.'/configuration/settings/email')->assertSessionHasNoErrors();
    $this->post('http://b.localhost/admin/settings/email/test', $data)->assertForbidden();
    expect($this->emailTransport->messages)->toHaveCount(1);
});

it('constructs isolated fixed SMTP transports with mandatory STARTTLS and TLS verification', function (): void {
    $factory = new ProtonSmtpTransport;
    $first = $factory->build(new EmailConnection('a@example.test', 'A', 'test-a'));
    $second = $factory->build(new EmailConnection('b@example.test', 'B', 'test-b'));
    expect($first)->not->toBe($second)->and($first->isTlsRequired())->toBeTrue()->and($first->isAutoTls())->toBeTrue()
        ->and($first->getStream()->getHost())->toBe('smtp.protonmail.ch')->and($first->getStream()->getPort())->toBe(587)
        ->and($first->getStream()->getTimeout())->toBe(15.0)->and($first->getStream()->getStreamOptions()['ssl']['verify_peer'])->toBeTrue()
        ->and($first->getStream()->getStreamOptions()['ssl']['verify_peer_name'])->toBeTrue()
        ->and($first->getUsername())->toBe('a@example.test')->and($second->getUsername())->toBe('b@example.test');
});
