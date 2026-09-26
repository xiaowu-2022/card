<?php

use App\Application\Inbox\InboxDelivery;
use App\Application\Inbox\InboxWriter;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Infrastructure\Auth\ConsumerDeviceToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->where('email', 'user@a.localhost')->firstOrFail();
});

function consumerLogin($test): string
{
    return $test->postJson('http://a.localhost/api/mobile/v1/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password', 'device_name' => 'Offline test'])
        ->assertCreated()->json('token');
}

it('issues hash-only scoped expiring tokens and rejects cross-company use', function () {
    $plain = consumerLogin($this);
    $token = ConsumerDeviceToken::firstOrFail();
    expect($token->token)->not->toBe($plain)->toHaveLength(64);
    expect($token->tenant_id)->toBe($this->tenant->id);
    $this->withToken($plain)->getJson('http://a.localhost/api/mobile/v1/bootstrap')->assertOk()->assertJsonPath('user.id', $this->user->id)->assertJsonPath('csrfToken', null);
    $this->withToken($plain)->getJson('http://b.localhost/api/mobile/v1/account')->assertUnauthorized();
    $this->withToken($plain)->getJson('http://a.localhost/api/v1/account')->assertUnauthorized();
    $this->withToken($plain)->getJson('http://admin.localhost/api/mobile/v1/account')->assertNotFound();
});

it('keeps native APIs independent of browser and administrator sessions', function () {
    $this->actingAs($this->user, 'tenant_user');
    $this->getJson('http://a.localhost/api/mobile/v1/bootstrap')->assertOk()->assertJsonPath('user', null);
    $this->getJson('http://a.localhost/api/mobile/v1/account')->assertUnauthorized();
});

it('invalidates expired tokens and tokens issued before password/session revocation', function () {
    $plain = consumerLogin($this);
    $this->user->forceFill(['session_version' => 1])->save();
    $this->withToken($plain)->getJson('http://a.localhost/api/mobile/v1/account')->assertUnauthorized();
    $this->flushHeaders();
    $plain = consumerLogin($this);
    ConsumerDeviceToken::query()->where('session_version', 1)->update(['expires_at' => now()->subMinute()]);
    $this->withToken($plain)->getJson('http://a.localhost/api/mobile/v1/account')->assertUnauthorized();
});

it('allows restricted inbox reads but rejects operational support for suspended users', function () {
    $plain = consumerLogin($this);
    $this->user->forceFill(['status' => 'SUSPENDED'])->save();
    $this->withToken($plain)->getJson('http://a.localhost/api/mobile/v1/messages')->assertOk();
    $this->withToken($plain)->getJson('http://a.localhost/api/mobile/v1/support')->assertForbidden();
    $this->user->forceFill(['status' => 'DISABLED'])->save();
    $this->withToken($plain)->getJson('http://a.localhost/api/mobile/v1/messages')->assertUnauthorized();
});

it('rejects closed companies and incorrect credentials with no token issuance', function () {
    $this->postJson('http://a.localhost/api/mobile/v1/login', ['identifier' => 'user@a.localhost', 'password' => 'incorrect'])->assertUnprocessable();
    expect(ConsumerDeviceToken::count())->toBe(0);
    $this->tenant->forceFill(['status' => 'CLOSED', 'closed_at' => now()])->save();
    $this->getJson('http://a.localhost/api/mobile/v1/bootstrap')->assertStatus(503);
});

it('keeps inbox GET read-only and scopes idempotent read operations to the recipient', function () {
    $plain = consumerLogin($this);
    DB::transaction(fn () => app(InboxWriter::class)->record($this->tenant->id, $this->user->id, 'api-test', 'deposit', ['amount' => '0.000000000000000001', 'asset' => 'ETH'], '/funds'));
    $id = DB::table('inbox_events')->where('event_key', 'api-test')->value('id');
    app(InboxDelivery::class)->attempt($this->tenant->id, $id);
    $this->withToken($plain)->getJson('http://a.localhost/api/mobile/v1/messages/'.$id)->assertOk()->assertJsonPath('readAt', null)->assertJsonPath('parameters.amount', '0.000000000000000001');
    $this->withToken($plain)->getJson('http://a.localhost/api/mobile/v1/unread')->assertJsonPath('messages', 1);
    $this->withToken($plain)->postJson('http://a.localhost/api/mobile/v1/messages/'.$id.'/read')->assertNoContent();
    $this->withToken($plain)->postJson('http://a.localhost/api/mobile/v1/messages/'.$id.'/read')->assertNoContent();
    $this->withToken($plain)->getJson('http://a.localhost/api/mobile/v1/unread')->assertJsonPath('messages', 0);
    $this->flushHeaders();
    User::create(['tenant_id' => $this->tenant->id, 'email' => 'other-api@example.test', 'password_hash' => Hash::make('password'), 'status' => 'ACTIVE']);
    $other = $this->postJson('http://a.localhost/api/mobile/v1/login', ['identifier' => 'other-api@example.test', 'password' => 'password'])->json('token');
    $this->withToken($other)->getJson('http://a.localhost/api/mobile/v1/messages/'.$id)->assertNotFound();
    $this->withToken($other)->postJson('http://a.localhost/api/mobile/v1/messages/'.$id.'/read')->assertNotFound();
});

it('revokes only the current device on logout and preserves other devices', function () {
    $one = consumerLogin($this);
    $two = consumerLogin($this);
    $this->withToken($one)->postJson('http://a.localhost/api/mobile/v1/logout')->assertNoContent();
    $this->withToken($one)->getJson('http://a.localhost/api/mobile/v1/account')->assertUnauthorized();
    $this->withToken($two)->getJson('http://a.localhost/api/mobile/v1/account')->assertOk();
});

it('supports existing scoped H5 sessions without exposing a bearer token', function () {
    $this->getJson('http://a.localhost/api/v1/bootstrap')->assertOk()->assertJsonPath('user', null);
    $this->postJson('http://a.localhost/api/v1/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])->assertOk()->assertJsonMissingPath('token');
    $this->getJson('http://a.localhost/api/v1/account')->assertOk();
    $this->getJson('http://b.localhost/api/v1/account')->assertUnauthorized();
});

it('rejects malformed bearer IDs without database errors', function () {
    foreach (['999999999999999999999999999999|'.str_repeat('a', 64), '1|short', '0|'.str_repeat('a', 64), '1|'.str_repeat('!', 64)] as $token) {
        $this->withToken($token)->getJson('http://a.localhost/api/mobile/v1/account')->assertUnauthorized();
    }
});

it('preserves precise read-only asset and card query results without creating financial rows', function () {
    $plain = consumerLogin($this);
    $before = [DB::table('ledger_entries')->count(), DB::table('wallets')->count()];
    $this->withToken($plain)->getJson('http://a.localhost/api/mobile/v1/assets')->assertOk()->assertJsonCount(4, 'assets');
    $this->withToken($plain)->getJson('http://a.localhost/api/mobile/v1/cards')->assertOk()->assertJsonStructure(['cards']);
    expect([DB::table('ledger_entries')->count(), DB::table('wallets')->count()])->toBe($before);
});
