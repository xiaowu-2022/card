<?php

use App\Application\User\CreateRegistrationChallengeAction;
use App\Application\User\RegisterUserAction;
use App\Application\User\VerifyRegistrationChallengeAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Notification\Contracts\SmsVerificationSender;
use App\Domain\Notification\Models\PlatformSmsProfile;
use App\Domain\Notification\Services\TenantSmsPolicy;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\RegistrationChallengeStatus;
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
        'resend_interval_seconds' => 90, 'code_ttl_seconds' => 300, 'current_password' => 'local-password',
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
    [['current_password' => 'wrong-password'], 'current_password'],
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

it('advertises phone registration only for the currently configured company', function (): void {
    $this->get('http://a.localhost/register')->assertInertia(fn (Assert $page) => $page->where('registration.phoneAvailable', false));
    $settings = configureSms($this->smsTenant);
    $this->get('http://a.localhost/register')->assertInertia(fn (Assert $page) => $page->where('registration.phoneAvailable', true));
    $this->get('http://b.localhost/register')->assertInertia(fn (Assert $page) => $page->where('registration.phoneAvailable', false));
    $settings->update(['enabled' => false]);
    $this->get('http://a.localhost/register')->assertInertia(fn (Assert $page) => $page->where('registration.phoneAvailable', false));
    expect(fn () => app(CreateRegistrationChallengeAction::class)->execute($this->smsOther, RegistrationChannel::Phone, '13800138000', 'CN'))->toThrow(DomainException::class);
    Http::assertNothingSent();
});

it('sends the approved Aliyun request after challenge persistence and verifies a real-adapter phone registration', function (): void {
    configureSms($this->smsTenant);
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($level) {
        expect(DB::transactionLevel())->toBe($level);
        $challenge = RegistrationChallenge::query()->where('tenant_id', $this->smsTenant->id)->sole();
        expect($challenge->sms_delivery_uncertain)->toBeTrue();
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        expect($request->method())->toBe('POST')->and(parse_url($request->url(), PHP_URL_HOST))->toBe('dysmsapi.aliyuncs.com')
            ->and($query['PhoneNumbers'])->toBe('8613800138000')->and($query['SignName'])->toBe('测试签名')
            ->and($query['TemplateCode'])->toBe('SMS_123456')->and(json_decode($query['TemplateParam'], true)['code'])->toMatch('/^\d{6}$/')
            ->and($request->header('x-acs-action'))->toBe(['SendSms'])->and($request->header('x-acs-version'))->toBe(['2017-05-25'])
            ->and($request->header('Authorization')[0])->toStartWith('ACS3-HMAC-SHA256 Credential=TestKeyA,');

        return Http::response(['Code' => 'OK', 'RequestId' => 'safe-request', 'BizId' => 'safe-biz']);
    });
    $created = app(CreateRegistrationChallengeAction::class)->execute($this->smsTenant, RegistrationChannel::Phone, '138 0013 8000', 'CN');
    expect($created->deliveryUncertain)->toBeFalse()->and($created->challenge->fresh()->sms_delivery_uncertain)->toBeFalse()
        ->and($created->challenge->expires_at->diffInSeconds($created->challenge->created_at, true))->toBeLessThanOrEqual(300)
        ->and($created->challenge->code_hash)->not->toBe($created->rawCode);
    app(VerifyRegistrationChallengeAction::class)->execute($this->smsTenant->id, $created->challenge->id, $created->rawCode);
    $user = app(RegisterUserAction::class)->execute($this->smsTenant, $created->challenge->id, 'StrongPass1234', 'SMS user');
    expect($user->phone)->toBe('+8613800138000')->and($user->phone_verified_at)->not->toBeNull();
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

it('keeps unknown sends verifiable without a blind resend or a second session takeover', function (string $outcome): void {
    configureSms($this->smsTenant);
    Http::fake(function () use ($outcome) {
        if ($outcome === 'timeout') {
            throw new ConnectionException('Never expose secret or destination from request URL');
        }

        return $outcome === '5xx' ? Http::response(['Code' => 'InternalError'], 500) : Http::response('malformed');
    });
    $action = app(CreateRegistrationChallengeAction::class);
    $created = $action->execute($this->smsTenant, RegistrationChannel::Phone, '13800138000', 'CN');
    expect($created->deliveryUncertain)->toBeTrue()->and($created->challenge->fresh()->sms_delivery_uncertain)->toBeTrue();
    $this->travel(100)->seconds();
    $reopened = $action->execute($this->smsTenant, RegistrationChannel::Phone, '13800138000', 'CN', reusableChallengeIds: [$created->challenge->id]);
    expect($reopened->challenge->id)->toBe($created->challenge->id)->and($reopened->rawCode)->toBeNull();
    expect(fn () => $action->execute($this->smsTenant, RegistrationChannel::Phone, '13800138000', 'CN'))->toThrow(DomainException::class);
    expect(RegistrationChallenge::query()->where('tenant_id', $this->smsTenant->id)->count())->toBe(1);
    app(VerifyRegistrationChallengeAction::class)->execute($this->smsTenant->id, $created->challenge->id, $created->rawCode);
    expect($created->challenge->fresh()->status)->toBe(RegistrationChallengeStatus::Verified);
})->with(['timeout', '5xx', 'malformed']);

it('returns only sanitized provider rejection and respects company resend timing', function (): void {
    configureSms($this->smsTenant);
    Http::fakeSequence()->push(['Code' => 'isv.INVALID_PARAMETERS', 'Message' => 'secret-A phone 13800138000 code 123456'])->push(['Code' => 'OK']);
    $action = app(CreateRegistrationChallengeAction::class);
    try {
        $action->execute($this->smsTenant, RegistrationChannel::Phone, '13800138000', 'CN');
        test()->fail('Expected sanitized rejection');
    } catch (DomainException $error) {
        expect($error->errorCode)->toBe('SMS_SEND_REJECTED')->and($error->getMessage())->not->toContain('secret', '13800138000', '123456')
            ->and($error->getPrevious())->toBeNull();
    }
    expect(RegistrationChallenge::query()->where('tenant_id', $this->smsTenant->id)->sole()->sms_delivery_uncertain)->toBeFalse();
    $this->travel(65)->seconds();
    expect(fn () => $action->execute($this->smsTenant, RegistrationChannel::Phone, '13800138000', 'CN'))->toThrow(DomainException::class, 'Please wait');
    Http::assertSentCount(1);
    $this->travel(30)->seconds();
    $created = $action->execute($this->smsTenant, RegistrationChannel::Phone, '13800138000', 'CN');
    expect($created->deliveryUncertain)->toBeFalse();
});

it('gives an uncertain SMS challenge to its browser session without exposing contact or code', function (): void {
    configureSms($this->smsTenant);
    Http::fake(['*' => Http::response('unconfirmed', 503)]);
    $response = $this->post('http://a.localhost/register/challenges', ['channel' => 'PHONE', 'destination' => '13800138000', 'region' => 'CN', 'invitation_code' => registrationTestInvitation()])->assertRedirect();
    $challenge = RegistrationChallenge::query()->where('tenant_id', $this->smsTenant->id)->sole();
    $response->assertSessionHas('registration.challenge_ids', [$challenge->id])->assertSessionHas('success');
    $this->get('http://a.localhost/register/challenges/'.$challenge->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('challenge.id', $challenge->id)->missing('challenge.destination')->missing('challenge.code_hash'));
    Http::assertSentCount(1);
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

it('expires an uncertain authentication request before allowing a new attempt', function (): void {
    configureSms($this->smsTenant);
    Http::fakeSequence()->push('unconfirmed', 503)->push(['Code' => 'OK']);
    $action = app(CreateRegistrationChallengeAction::class);
    $first = $action->execute($this->smsTenant, RegistrationChannel::Phone, '13800138000', 'CN');
    $this->travel(301)->seconds();
    $second = $action->execute($this->smsTenant, RegistrationChannel::Phone, '13800138000', 'CN');
    expect($first->challenge->fresh()->status)->toBe(RegistrationChallengeStatus::Expired)
        ->and($second->challenge->id)->not->toBe($first->challenge->id)->and($second->deliveryUncertain)->toBeFalse();
    Http::assertSentCount(2);
});

it('counts rejected SMS sends toward hourly limits', function (): void {
    configureSms($this->smsTenant);
    config(['user-auth.send_limit_per_hour' => 2]);
    Http::fake(['*' => Http::response(['Code' => 'isv.BUSINESS_LIMIT_CONTROL'])]);
    $payload = ['channel' => 'PHONE', 'destination' => '13800138000', 'region' => 'CN', 'invitation_code' => registrationTestInvitation()];
    $this->postJson('http://a.localhost/register/challenges', $payload)->assertStatus(503);
    $this->travel(91)->seconds();
    $this->postJson('http://a.localhost/register/challenges', $payload)->assertStatus(503);
    $this->travel(91)->seconds();
    $this->postJson('http://a.localhost/register/challenges', $payload)->assertStatus(429)->assertJsonValidationErrors('destination');
    Http::assertSentCount(2);
});
