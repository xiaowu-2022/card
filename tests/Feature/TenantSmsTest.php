<?php

use App\Application\User\CreateRegistrationChallengeAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notification\Contracts\SmsVerificationSender;
use App\Domain\Notification\Exceptions\SmsDeliveryUnknown;
use App\Domain\Notification\Models\PlatformSmsProfile;
use App\Domain\Notification\Services\TenantSmsPolicy;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Models\RegistrationChallenge;
use App\Infrastructure\Sms\AliyunSmsVerificationSender;
use App\Support\Errors\DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed();
    config(['inertia.ssr.enabled' => false]);
    Cache::flush();
    Http::preventStrayRequests();
    $this->smsTenant = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->smsOther = Tenant::query()->where('slug', 'tenant-b')->firstOrFail();
    $this->smsAdmin = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->app->bind(SmsVerificationSender::class, AliyunSmsVerificationSender::class);
});

function smsSettingsPayload(array $overrides = []): array
{
    return [...[
        'name' => 'Default Sms', 'enabled' => true, 'access_key_id' => 'TestKeyA', 'access_key_secret' => 'test-secret-A',
        'sign_name' => '测试签名', 'verification_template_code' => 'SMS_123456', 'existing_account_template_code' => '',
        'resend_interval_seconds' => 90, 'code_ttl_seconds' => 300,
    ], ...$overrides];
}

function configureSms(Tenant $tenant, array $overrides = []): PlatformSmsProfile
{
    $data = smsSettingsPayload($overrides);
    unset($data['current_password'], $data['name']);

    $profile = PlatformSmsProfile::query()->create(['name' => $tenant->name, ...$data]);
    bindNotificationTestProfile($tenant, 'sms', $profile->id);

    return $profile;
}

it('stores credentials encrypted only for the resolved company and never reveals them', function (): void {
    $other = configureSms($this->smsOther, ['access_key_id' => 'TestKeyB', 'access_key_secret' => 'test-secret-B']);
    $this->actingAs($this->smsAdmin, 'platform_admin')->post(notificationProfileUrl('sms', $this->smsTenant), smsSettingsPayload())
        ->assertRedirect('/platform/settings/sms')->assertSessionHasNoErrors();
    bindNotificationTestProfile($this->smsTenant, 'sms', PlatformSmsProfile::query()->where('name', 'Default Sms')->sole()->id);
    $this->actingAs(AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail(), 'tenant_admin');
    $settings = app(TenantSmsPolicy::class)->settings($this->smsTenant->id);
    expect($settings->access_key_id)->toBe('TestKeyA')->and($settings->access_key_secret)->toBe('test-secret-A')
        ->and($settings->getRawOriginal('access_key_id'))->not->toContain('TestKeyA')
        ->and($settings->getRawOriginal('access_key_secret'))->not->toContain('test-secret-A')
        ->and($settings->toArray())->not->toHaveKeys(['access_key_id', 'access_key_secret'])
        ->and($other->fresh()->access_key_id)->toBe('TestKeyB');
    $response = $this->get('http://a.localhost/admin/settings/sms')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('tenant-admin/Settings')->where('section', 'sms')
        ->where('settings.sms.credentialsConfigured', true)->where('settings.sms.enabled', true)
        ->missing('settings.sms.access_key_id')->missing('settings.sms.access_key_secret'));
    expect($response->getContent())->not->toContain('TestKeyA', 'TestKeyB', 'test-secret-A', 'test-secret-B', $settings->getRawOriginal('access_key_secret'));
    expect(AuditLog::query()->whereNull('tenant_id')->where('action', 'PLATFORM_SMS_PROFILE_SAVED')->sole()->toJson())
        ->not->toContain('TestKeyA', 'test-secret-A');
    $this->get('http://a.localhost/admin/settings/branding')->assertInertia(fn (Assert $page) => $page->missing('settings.sms'));
    Http::assertNothingSent();
});

it('keeps blank credentials replaces them as a pair and disables without revealing', function (): void {
    configureSms($this->smsTenant);
    $this->actingAs($this->smsAdmin, 'platform_admin');
    $this->post(notificationProfileUrl('sms', $this->smsTenant), smsSettingsPayload(['access_key_id' => '', 'access_key_secret' => '', 'sign_name' => '新签名']))->assertSessionHasNoErrors();
    $settings = app(TenantSmsPolicy::class)->settings($this->smsTenant->id);
    expect($settings->access_key_id)->toBe('TestKeyA')->and($settings->sign_name)->toBe('新签名');
    $this->post(notificationProfileUrl('sms', $this->smsTenant), smsSettingsPayload(['access_key_id' => 'NewKey', 'access_key_secret' => 'new-secret']))->assertSessionHasNoErrors();
    expect($settings->fresh()->access_key_id)->toBe('NewKey');
    $this->post(notificationProfileUrl('sms', $this->smsTenant), smsSettingsPayload(['enabled' => false, 'access_key_id' => '', 'access_key_secret' => '']))->assertSessionHasNoErrors();
    expect($settings->fresh()->enabled)->toBeFalse()->and($settings->fresh()->access_key_secret)->toBe('new-secret');
    Http::assertNothingSent();
});

it('keeps company credential writes forbidden and rejects platform tenant overrides', function (): void {
    $companyOwner = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($companyOwner, 'tenant_admin')->get('http://b.localhost/admin/settings/sms')->assertForbidden();
    $this->post('http://a.localhost/admin/settings/sms', smsSettingsPayload())->assertForbidden();
    $this->actingAs($this->smsAdmin, 'platform_admin')->post(notificationProfileUrl('sms', $this->smsTenant), smsSettingsPayload(['tenant_id' => $this->smsOther->id]))->assertSessionHasErrors('tenant_id');
    expect(PlatformSmsProfile::query()->count())->toBe(0);
});

it('validates settings and never flashes submitted credentials', function (array $overrides, string $field): void {
    $this->actingAs($this->smsAdmin, 'platform_admin')->from('http://a.localhost/admin/settings/sms')
        ->post(notificationProfileUrl('sms', $this->smsTenant), smsSettingsPayload($overrides))->assertSessionHasErrors($field)
        ->assertSessionMissing('_old_input.access_key_id')->assertSessionMissing('_old_input.access_key_secret')->assertSessionMissing('_old_input.current_password');
    expect(PlatformSmsProfile::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([

    [['access_key_secret' => ''], 'access_key_secret'],
    [['access_key_id' => ''], 'access_key_id'],
    [['verification_template_code' => 'arbitrary'], 'verification_template_code'],
    [['sign_name' => ''], 'sign_name'],
    [['resend_interval_seconds' => 59], 'resend_interval_seconds'],
    [['code_ttl_seconds' => 60], 'code_ttl_seconds'],
    [['endpoint' => 'http://127.0.0.1'], 'endpoint'],
]);

it('cannot enable SMS without previously stored or newly supplied credentials', function (): void {
    $this->actingAs($this->smsAdmin, 'platform_admin')->post(notificationProfileUrl('sms', $this->smsTenant), smsSettingsPayload(['access_key_id' => '', 'access_key_secret' => '']))
        ->assertSessionHasErrors('form');
    expect(PlatformSmsProfile::query()->count())->toBe(0);
});

it('never enables phone registration through SMS configuration', function (): void {
    configureSms($this->smsTenant);
    foreach (['a.localhost', 'b.localhost'] as $host) {
        $this->get("http://{$host}/register")->assertInertia(fn (Assert $page) => $page->missing('registration.phoneAvailable'));
    }
    expect(fn () => app(CreateRegistrationChallengeAction::class)->execute($this->smsTenant, RegistrationChannel::Phone, '13800138000', 'CN'))->toThrow(DomainException::class, 'Only email');
    Http::assertNothingSent();
});

it('preserves the approved Aliyun transport for independent SMS workflows', function (): void {
    configureSms($this->smsTenant);
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($level) {
        expect(DB::transactionLevel())->toBe($level);
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        expect($request->method())->toBe('POST')->and(parse_url($request->url(), PHP_URL_HOST))->toBe('dysmsapi.aliyuncs.com')
            ->and($query['PhoneNumbers'])->toBe('8613800138000')->and($query['SignName'])->toBe('测试签名')
            ->and($query['TemplateCode'])->toBe('SMS_123456')->and(json_decode($query['TemplateParam'], true)['code'])->toMatch('/^\d{6}$/')
            ->and($request->header('x-acs-action'))->toBe(['SendSms'])->and($request->header('x-acs-version'))->toBe(['2017-05-25'])
            ->and($request->header('Authorization')[0])->toStartWith('ACS3-HMAC-SHA256 Credential=TestKeyA,');

        return Http::response(['Code' => 'OK', 'RequestId' => 'safe-request', 'BizId' => 'safe-biz']);
    });
    app(SmsVerificationSender::class)->sendVerificationCode($this->smsTenant, '+8613800138000', '123456');
    Http::assertSentCount(1);
});

it('uses each company credential and template without leaking or caching another company configuration', function (): void {
    configureSms($this->smsTenant);
    configureSms($this->smsOther, ['access_key_id' => 'TestKeyB', 'verification_template_code' => 'SMS_654321']);
    Http::fake(['*' => Http::response(['Code' => 'OK'])]);
    $sender = app(SmsVerificationSender::class);
    $sender->sendVerificationCode($this->smsTenant, '+8613800138000', '123456');
    $sender->sendVerificationCode($this->smsOther, '+60123456789', '654321');
    $records = Http::recorded();
    expect($records[0][0]->header('Authorization')[0])->toContain('Credential=TestKeyA,')
        ->and($records[1][0]->header('Authorization')[0])->toContain('Credential=TestKeyB,')
        ->and($records[1][0]->url())->toContain('TemplateCode=SMS_654321', 'PhoneNumbers=60123456789');
});

it('reports unknown SMS transport outcomes without inventing delivery', function (string $outcome): void {
    configureSms($this->smsTenant);
    $attempts = 0;
    Http::fake(function () use ($outcome, &$attempts) {
        $attempts++;
        if ($outcome === 'timeout') {
            throw new ConnectionException('transport timeout');
        }

        return $outcome === '5xx' ? Http::response(['Code' => 'InternalError'], 500) : Http::response('malformed');
    });
    expect(fn () => app(SmsVerificationSender::class)->sendVerificationCode($this->smsTenant, '+8613800138000', '123456'))
        ->toThrow(SmsDeliveryUnknown::class);
    expect($attempts)->toBe(1);
})->with(['timeout', '5xx', 'malformed']);

it('returns only sanitized SMS provider rejection', function (): void {
    configureSms($this->smsTenant);
    Http::fake(['*' => Http::response(['Code' => 'isv.INVALID_PARAMETERS', 'Message' => 'secret-A phone 13800138000 code 123456'])]);
    try {
        app(SmsVerificationSender::class)->sendVerificationCode($this->smsTenant, '+8613800138000', '123456');
        test()->fail('Expected sanitized rejection');
    } catch (DomainException $error) {
        expect($error->errorCode)->toBe('SMS_SEND_REJECTED')->and($error->getMessage())->not->toContain('secret', '13800138000', '123456')
            ->and($error->getPrevious())->toBeNull();
    }
});

it('does not send a misleading OTP to existing accounts and supports an optional notification template', function (): void {
    $settings = configureSms($this->smsTenant);
    Http::fake(['*' => Http::response(['Code' => 'OK'])]);
    $sender = app(SmsVerificationSender::class);
    $sender->sendExistingAccountNotice($this->smsTenant, '+8613800138000');
    Http::assertNothingSent();
    $settings->update(['existing_account_template_code' => 'SMS_999999']);
    $sender->sendExistingAccountNotice($this->smsTenant, '+8613800138000');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'TemplateCode=SMS_999999') && ! str_contains($request->url(), 'TemplateParam'));
});

it('blocks public SMS registration before any provider request', function (): void {
    configureSms($this->smsTenant);
    $this->postJson('http://a.localhost/register/challenges', ['channel' => 'PHONE', 'destination' => '13800138000', 'region' => 'CN', 'invitation_code' => registrationTestInvitation()])
        ->assertUnprocessable()->assertJsonValidationErrors('channel');
    Http::assertNothingSent();
    expect(RegistrationChallenge::query()->where('channel', RegistrationChannel::Phone)->exists())->toBeFalse();
});
