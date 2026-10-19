<?php

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->seed();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->where('email', 'user@a.localhost')->firstOrFail();
});

it('uses bearer authentication for browser requests without CSRF or login cookies', function () {
    $plain = $this->postJson('http://a.localhost/api/mobile/v1/login', [
        'identifier' => 'user@a.localhost', 'password' => 'local-password', 'device_name' => 'H5',
    ])->assertCreated()->assertCookieMissing('consumer_remember')->json('token');
    $this->withToken($plain)->getJson('http://a.localhost/api/mobile/v1/bootstrap?defer_unread=1')
        ->assertOk()->assertJsonPath('user.id', $this->user->id)->assertJsonPath('csrfToken', null)
        ->assertCookieMissing('consumer_remember')->assertHeader('Cache-Control', 'no-store, private');
    $this->withToken($plain)->postJson('http://a.localhost/api/mobile/v1/logout')->assertNoContent();
    $this->withToken($plain)->getJson('http://a.localhost/api/mobile/v1/session')->assertUnauthorized();
});

it('keeps the native mailbox company scoped, revision bound and encrypted with a one-minute credential lifetime', function () {
    $channel = $this->postJson('http://a.localhost/api/mobile/v1/session/bridge/open')->assertOk()->json('channel');
    $plain = $this->postJson('http://a.localhost/api/mobile/v1/login', [
        'identifier' => 'user@a.localhost', 'password' => 'local-password',
    ])->assertCreated()->json('token');
    $this->withHeaders(['X-Consumer-Bridge' => $channel])->postJson('http://a.localhost/api/mobile/v1/session/bridge/publish')->assertUnauthorized();
    $this->withToken($plain)->withHeaders(['X-Consumer-Bridge' => $channel])
        ->postJson('http://a.localhost/api/mobile/v1/session/bridge/publish')->assertOk()->assertJsonPath('revision', 1);
    $key = 'consumer-token-bridge:'.hash('sha256', $this->tenant->id.':'.$channel);
    expect(json_encode(Cache::get($key.':credential')))->not->toContain($plain);
    $this->flushHeaders();
    $this->withHeaders(['X-Consumer-Bridge' => $channel])->postJson('http://a.localhost/api/mobile/v1/session/bridge/claim', ['revision' => 1])
        ->assertOk()->assertJsonPath('token', $plain)->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson('http://b.localhost/api/mobile/v1/session/bridge/claim', ['revision' => 1])->assertGone();
    $this->postJson('http://a.localhost/api/mobile/v1/session/bridge/claim', ['revision' => 2])->assertGone();
    $this->withHeaders(['X-Consumer-Bridge' => str_repeat('X', 64)])
        ->postJson('http://a.localhost/api/mobile/v1/session/bridge/claim', ['revision' => 1])->assertGone();
    $this->travel(61)->seconds();
    $this->withHeaders(['X-Consumer-Bridge' => $channel])->postJson('http://a.localhost/api/mobile/v1/session/bridge/claim', ['revision' => 1])->assertGone();
    expect(Cache::get($key.':credential'))->toBeNull();
});

it('rejects superseded mailbox revisions and cannot revive a logged-out credential', function () {
    $channel = $this->postJson('http://a.localhost/api/mobile/v1/session/bridge/open')->json('channel');
    $plain = $this->postJson('http://a.localhost/api/mobile/v1/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])->json('token');
    $this->withToken($plain)->withHeaders(['X-Consumer-Bridge' => $channel]);
    $this->postJson('http://a.localhost/api/mobile/v1/session/bridge/publish')->assertOk()->assertJsonPath('revision', 1);
    $this->postJson('http://a.localhost/api/mobile/v1/session/bridge/publish')->assertOk()->assertJsonPath('revision', 2);
    $this->postJson('http://a.localhost/api/mobile/v1/session/bridge/claim', ['revision' => 1])->assertGone();
    $this->postJson('http://a.localhost/api/mobile/v1/logout')->assertNoContent();
    $this->postJson('http://a.localhost/api/mobile/v1/session/bridge/publish')->assertUnauthorized();
    $this->flushHeaders();
    $copy = $this->withHeaders(['X-Consumer-Bridge' => $channel])->postJson('http://a.localhost/api/mobile/v1/session/bridge/claim', ['revision' => 2])->assertOk()->json('token');
    $this->withToken($copy)->getJson('http://a.localhost/api/mobile/v1/session')->assertUnauthorized();
});
