<?php

use App\Domain\User\Models\User;
use App\Domain\Tenant\Models\TenantDomain;
use App\Http\Middleware\RememberConsumerSession;
use App\Infrastructure\Auth\ConsumerDeviceToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed();
    $this->withCredentials();
});

function rememberedBrowser($test): string
{
    return $test->postJson('http://a.localhost/api/v1/login', [
        'identifier' => 'user@a.localhost', 'password' => 'local-password',
    ])->assertOk()->getCookie(RememberConsumerSession::COOKIE)->getValue();
}

function forgetBrowserSession($test): void
{
    Auth::forgetGuards();
    app('session')->driver()->flush();
}

it('restores a closed browser after 29 days and renews another 30 days without financial writes', function () {
    $cookie = rememberedBrowser($this);
    $token = ConsumerDeviceToken::firstOrFail();
    expect($token->abilities)->toBe(['browser']);
    $before = [DB::table('wallets')->count(), DB::table('ledger_entries')->count()];
    $this->travel(29)->days();
    forgetBrowserSession($this);
    $response = $this->withCookie(RememberConsumerSession::COOKIE, $cookie)
        ->getJson('http://a.localhost/api/v1/bootstrap')->assertOk()->assertJsonPath('user.email', 'user@a.localhost');
    expect($response->getCookie(RememberConsumerSession::COOKIE)->isHttpOnly())->toBeTrue();
    expect($response->getCookie(RememberConsumerSession::COOKIE)->getExpiresTime())->toBe(now()->addDays(30)->timestamp);
    expect($token->fresh()->expires_at->timestamp)->toBe(now()->addDays(30)->timestamp);
    $this->travel(29)->days();
    forgetBrowserSession($this);
    $this->getJson('http://a.localhost/api/v1/bootstrap')->assertOk()->assertJsonPath('user.email', 'user@a.localhost');
    expect(ConsumerDeviceToken::count())->toBe(1);
    expect([DB::table('wallets')->count(), DB::table('ledger_entries')->count()])->toBe($before);
    $this->assertGuest('platform_admin');
});

it('rejects a browser credential at exactly 30 inactive days without reviving it', function () {
    $cookie = rememberedBrowser($this);
    $expiry = ConsumerDeviceToken::firstOrFail()->expires_at;
    $this->travelTo($expiry);
    forgetBrowserSession($this);
    $this->withCookie(RememberConsumerSession::COOKIE, $cookie)->getJson('http://a.localhost/api/v1/bootstrap')
        ->assertOk()->assertJsonPath('user', null);
    expect(ConsumerDeviceToken::firstOrFail()->expires_at->timestamp)->toBe($expiry->timestamp);
});

it('deletes remembered login on explicit logout and rejects copied old cookies', function () {
    $cookie = rememberedBrowser($this);
    $this->withCookie(RememberConsumerSession::COOKIE, $cookie)->postJson('http://a.localhost/api/v1/logout')->assertNoContent();
    expect(ConsumerDeviceToken::count())->toBe(0);
    forgetBrowserSession($this);
    $this->getJson('http://a.localhost/api/v1/bootstrap')->assertOk()->assertJsonPath('user', null);
});

it('does not accept browser credentials across companies or as native bearer tokens', function () {
    $cookie = rememberedBrowser($this);
    forgetBrowserSession($this);
    $this->withCookie(RememberConsumerSession::COOKIE, $cookie)->getJson('http://b.localhost/api/v1/bootstrap')
        ->assertOk()->assertJsonPath('user', null);
    $this->withToken($cookie)->getJson('http://a.localhost/api/mobile/v1/account')->assertUnauthorized();
});

it('rejects remembered login after version revocation or account disablement', function ($change) {
    $cookie = rememberedBrowser($this);
    User::where('email', 'user@a.localhost')->update($change);
    forgetBrowserSession($this);
    $this->withCookie(RememberConsumerSession::COOKIE, $cookie)->getJson('http://a.localhost/api/v1/bootstrap')
        ->assertOk()->assertJsonPath('user', null);
    expect(ConsumerDeviceToken::count())->toBe(1);
})->with([[['session_version' => 1]], [['status' => 'DISABLED']]]);

it('renews native tokens on valid activity but does not renew revoked or expired tokens', function () {
    $plain = $this->postJson('http://a.localhost/api/mobile/v1/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])
        ->assertCreated()->json('token');
    $this->travel(29)->days();
    $this->withToken($plain)->getJson('http://a.localhost/api/mobile/v1/account')->assertOk();
    $expiry = ConsumerDeviceToken::firstOrFail()->expires_at;
    expect($expiry->timestamp)->toBe(now()->addDays(30)->timestamp);
    $this->travelTo($expiry);
    $this->getJson('http://a.localhost/api/mobile/v1/account')->assertUnauthorized();
    expect(ConsumerDeviceToken::firstOrFail()->expires_at->timestamp)->toBe($expiry->timestamp);
});

it('preserves remembered login on the password-changing browser only', function () {
    $cookie = rememberedBrowser($this);
    $this->withCookie(RememberConsumerSession::COOKIE, $cookie)
        ->post('http://a.localhost/account/security/password', [
            'current_password' => 'local-password', 'password' => 'UpdatedPassword123', 'password_confirmation' => 'UpdatedPassword123',
        ])->assertRedirect()->assertSessionHas('tenant_user_session_version', 1);
    expect(ConsumerDeviceToken::firstOrFail()->session_version)->toBe(1);
    forgetBrowserSession($this);
    $this->getJson('http://a.localhost/api/v1/bootstrap')->assertOk()->assertJsonPath('user.email', 'user@a.localhost');
});

it('restores the same encrypted WebView credential on another company domain and revokes both on logout', function () {
    $user = User::where('email', 'user@a.localhost')->firstOrFail();
    TenantDomain::create(['tenant_id' => $user->tenant_id, 'hostname' => 'second-a.localhost', 'domain_type' => 'CUSTOM_DOMAIN', 'status' => 'ACTIVE', 'is_primary' => false]);
    $encrypted = $this->postJson('https://a.localhost/api/v1/login', [
        'identifier' => $user->email, 'password' => 'local-password',
    ])->assertOk()->getCookie(RememberConsumerSession::COOKIE, false)->getValue();
    $before = [DB::table('wallets')->count(), DB::table('ledger_entries')->count()];
    forgetBrowserSession($this);
    $this->withHeader('X-Consumer-Webview', '1')->withUnencryptedCookie(RememberConsumerSession::COOKIE, $encrypted)
        ->getJson('https://second-a.localhost/api/v1/bootstrap')->assertOk()->assertJsonPath('user.id', $user->id);
    expect(ConsumerDeviceToken::count())->toBe(1);
    expect([DB::table('wallets')->count(), DB::table('ledger_entries')->count()])->toBe($before);
    $this->postJson('https://second-a.localhost/api/v1/logout')->assertNoContent();
    expect(ConsumerDeviceToken::count())->toBe(0);
    forgetBrowserSession($this);
    $this->getJson('https://a.localhost/api/v1/bootstrap')->assertOk()->assertJsonPath('user', null);
});

it('does not let the WebView marker authenticate a stale destination session without its remember credential', function () {
    rememberedBrowser($this);
    $this->withHeader('X-Consumer-Webview', '1')->getJson('http://a.localhost/api/v1/bootstrap')
        ->assertOk()->assertJsonPath('user', null);
});

it('replaces a destination consumer session owned by another account with the validated WebView credential owner', function () {
    $cookie = rememberedBrowser($this);
    $owner = User::where('email', 'user@a.localhost')->firstOrFail();
    // Simulate an old guard retained by the destination host. It is never the
    // source of authority for an app-restored remember credential.
    $other = User::where('email', 'user@b.localhost')->firstOrFail();
    Auth::guard('tenant_user')->login($other);
    app('session')->driver()->put('contact_change_request', 'old-account-request');
    $this->withHeader('X-Consumer-Webview', '1')->withCookie(RememberConsumerSession::COOKIE, $cookie)
        ->getJson('http://a.localhost/api/v1/bootstrap')->assertOk()->assertJsonPath('user.id', $owner->id)
        ->assertSessionMissing('contact_change_request');
    expect(ConsumerDeviceToken::count())->toBe(1);
    $this->assertGuest('platform_admin');
});

it('restores a valid updated shared credential over an older same-user destination session', function () {
    $cookie = rememberedBrowser($this);
    // A password change preserves this device's token while an unused host
    // still has the old short-lived session version.
    User::where('email', 'user@a.localhost')->update(['session_version' => 1]);
    ConsumerDeviceToken::query()->update(['session_version' => 1]);
    $this->withHeader('X-Consumer-Webview', '1')->withCookie(RememberConsumerSession::COOKIE, $cookie)
        ->getJson('http://a.localhost/api/v1/bootstrap')->assertOk()->assertJsonPath('user.email', 'user@a.localhost')
        ->assertSessionHas('tenant_user_session_version', 1);
});
