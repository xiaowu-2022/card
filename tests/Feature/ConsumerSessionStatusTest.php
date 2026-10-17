<?php

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Http\Middleware\RememberConsumerSession;
use App\Infrastructure\Auth\ConsumerDeviceToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed();
    Http::preventStrayRequests();
    $this->withCredentials();
    $this->tenant = Tenant::where('slug', 'tenant-a')->sole();
    $this->user = User::where('tenant_id', $this->tenant->id)->where('email', 'user@a.localhost')->sole();
});

function sessionStatusCookie($test): string
{
    return $test->postJson('http://a.localhost/api/v1/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])
        ->assertOk()->getCookie(RememberConsumerSession::COOKIE)->getValue();
}

it('returns only company identity and confirmed guest status without loading bootstrap data', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });
    $counts = [DB::table('wallets')->count(), DB::table('ledger_entries')->count(), ConsumerDeviceToken::count()];
    $this->getJson('http://a.localhost/api/v1/session')->assertOk()->assertExactJson([
        'tenant' => ['id' => $this->tenant->id, 'slug' => $this->tenant->slug], 'user' => null, 'restricted' => false,
    ])->assertHeader('Cache-Control', 'no-store, private');
    foreach ($queries as $sql) {
        foreach (['inbox_', 'support_', 'media_storage', 'tenant_brandings', 'tenant_locales', 'user_preferences'] as $table) {
            expect($sql)->not->toContain($table);
        }
    }
    expect([DB::table('wallets')->count(), DB::table('ledger_entries')->count(), ConsumerDeviceToken::count()])->toBe($counts);
    Http::assertNothingSent();
});

it('restores remembered identity through the existing scoped auth middleware without provisioning funds', function () {
    $cookie = sessionStatusCookie($this);
    Auth::forgetGuards();
    app('session')->driver()->flush();
    $counts = [DB::table('wallets')->count(), DB::table('ledger_entries')->count()];
    $this->withCookie(RememberConsumerSession::COOKIE, $cookie)->withHeader('X-Consumer-Webview', '1')
        ->getJson('http://a.localhost/api/v1/session')->assertOk()->assertExactJson([
            'tenant' => ['id' => $this->tenant->id, 'slug' => $this->tenant->slug], 'user' => ['id' => $this->user->id], 'restricted' => false,
        ]);
    expect([DB::table('wallets')->count(), DB::table('ledger_entries')->count()])->toBe($counts);
    $this->assertGuest('platform_admin');
    Http::assertNothingSent();
});

it('confirms expired or revoked credentials as guest and does not revive the token', function () {
    $cookie = sessionStatusCookie($this);
    $expiry = ConsumerDeviceToken::sole()->expires_at;
    $this->travelTo($expiry);
    Auth::forgetGuards();
    app('session')->driver()->flush();
    $this->withCookie(RememberConsumerSession::COOKIE, $cookie)->withHeader('X-Consumer-Webview', '1')
        ->getJson('http://a.localhost/api/v1/session')->assertOk()->assertJsonPath('user', null);
    expect(ConsumerDeviceToken::sole()->expires_at->timestamp)->toBe($expiry->timestamp);
});

it('never accepts a remembered credential belonging to another company', function () {
    $cookie = sessionStatusCookie($this);
    Auth::forgetGuards();
    app('session')->driver()->flush();
    $other = Tenant::where('slug', 'tenant-b')->sole();
    $this->withCookie(RememberConsumerSession::COOKIE, $cookie)->withHeader('X-Consumer-Webview', '1')
        ->getJson('http://b.localhost/api/v1/session')->assertOk()->assertExactJson([
            'tenant' => ['id' => $other->id, 'slug' => $other->slug], 'user' => null, 'restricted' => false,
        ]);
});

it('retains suspended user restriction and rejects session version revocation', function () {
    $cookie = sessionStatusCookie($this);
    $this->user->update(['status' => 'SUSPENDED']);
    Auth::forgetGuards();
    app('session')->driver()->flush();
    $this->withCookie(RememberConsumerSession::COOKIE, $cookie)->withHeader('X-Consumer-Webview', '1')
        ->getJson('http://a.localhost/api/v1/session')->assertOk()->assertJsonPath('user.id', $this->user->id)->assertJsonPath('restricted', true);
    $this->user->update(['session_version' => $this->user->session_version + 1]);
    Auth::forgetGuards();
    app('session')->driver()->flush();
    $this->getJson('http://a.localhost/api/v1/session')->assertOk()->assertJsonPath('user', null);
});

it('supports native bearer scope while rejecting browser credentials on mobile', function () {
    $token = $this->postJson('http://a.localhost/api/mobile/v1/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])
        ->assertCreated()->json('token');
    $this->withToken($token)->getJson('http://a.localhost/api/mobile/v1/session')->assertOk()->assertJsonPath('user.id', $this->user->id);
    $this->getJson('http://b.localhost/api/mobile/v1/session')->assertUnauthorized();
});
