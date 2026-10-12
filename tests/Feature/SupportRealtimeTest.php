<?php

use App\Application\Support\SendSupportMessageAction;
use App\Application\Support\SupportRealtime;
use App\Application\Support\SupportSocketOrigins;
use App\Application\Support\SupportUserAgents;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Support\Models\SupportConversation;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantDomain;
use App\Domain\User\Models\User;
use App\Infrastructure\Auth\ConsumerDeviceToken;
use App\Infrastructure\Support\CompanySupportSocketServer;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Reverb\Application;
use Laravel\Reverb\Connection;
use Laravel\Reverb\Contracts\WebSocketConnection;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Laravel\Reverb\Protocols\Pusher\Exceptions\InvalidOrigin;
use Laravel\Reverb\Protocols\Pusher\Server;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->company = Tenant::where('slug', 'tenant-a')->sole();
    $this->other = Tenant::where('slug', 'tenant-b')->sole();
    $this->customer = User::where('tenant_id', $this->company->id)->firstOrFail();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->sole();
    $this->admin = AdminUser::where('email', 'owner@a.localhost')->sole();
    config(['support-realtime.enabled' => true, 'broadcasting.connections.reverb.key' => 'offline-key', 'broadcasting.connections.reverb.secret' => 'offline-secret', 'broadcasting.connections.reverb.app_id' => 'offline-app']);
});

it('returns only own user channel and rejects foreign, team, platform and forged principals', function () {
    $before = [SupportConversation::count(), DB::table('ledger_entries')->count(), DB::table('support_messages')->count()];
    $url = 'http://a.localhost/api/v1/support/realtime';
    $this->actingAs($this->customer, 'tenant_user')->getJson($url)->assertOk()
        ->assertExactJson(['enabled' => true, 'path' => '/app/offline-key', 'port' => null, 'channels' => [SupportRealtime::userChannel($this->company->id, $this->customer->id)]])
        ->assertHeader('Cache-Control', 'no-store, private');
    $input = ['socket_id' => '123.456', 'channel_name' => SupportRealtime::userChannel($this->company->id, $this->customer->id)];
    $signature = hash_hmac('sha256', '123.456:'.$input['channel_name'], 'offline-secret');
    $this->postJson($url.'/auth', $input + ['tenant_id' => $this->other->id])->assertExactJson(['auth' => 'offline-key:'.$signature]);
    foreach ([SupportRealtime::platformChannel(), SupportRealtime::teamChannel($this->company->id), SupportRealtime::userChannel($this->other->id, $this->customer->id), SupportRealtime::adminChannel($this->owner->id)] as $channel) {
        $this->postJson($url.'/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->assertForbidden();
    }
    $this->postJson($url.'/auth', ['socket_id' => '../bad', 'channel_name' => $input['channel_name']])->assertUnprocessable();
    expect([SupportConversation::count(), DB::table('ledger_entries')->count(), DB::table('support_messages')->count()])->toBe($before);
});

it('revalidates enabled same-company consumer agents and revocation at every authorization', function () {
    config(['support-realtime.enabled' => false]);
    app(SupportUserAgents::class)->grant($this->company->id, $this->customer->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    config(['support-realtime.enabled' => true]);
    $url = 'http://a.localhost/api/v1/support/realtime';
    $this->actingAs($this->customer, 'tenant_user')->getJson($url)->assertJsonPath('channels.1', SupportRealtime::teamChannel($this->company->id));
    $input = ['socket_id' => '123.456', 'channel_name' => SupportRealtime::teamChannel($this->company->id)];
    $this->postJson($url.'/auth', $input)->assertOk();
    $this->postJson($url.'/auth', ['socket_id' => '123.456', 'channel_name' => SupportRealtime::teamChannel($this->other->id)])->assertForbidden();
    config(['support-realtime.enabled' => false]);
    app(SupportUserAgents::class)->grant($this->company->id, $this->customer->id, $this->owner->id, ['enabled' => false, 'revision' => 1]);
    config(['support-realtime.enabled' => true]);
    $this->postJson($url.'/auth', $input)->assertForbidden();
    $this->getJson($url)->assertJsonCount(1, 'channels');
});

it('uses exact active admin scope and isolated channels without secrets in configuration', function () {
    $this->actingAs($this->owner, 'platform_admin')->getJson('http://admin.localhost/platform/support/realtime')->assertOk()
        ->assertJsonPath('channels', [SupportRealtime::platformChannel(), SupportRealtime::adminChannel($this->owner->id)])
        ->assertJsonMissing(['secret' => 'offline-secret']);
    $this->actingAs($this->admin, 'tenant_admin')->getJson('http://a.localhost/admin/support/realtime')->assertOk()
        ->assertJsonPath('channels', [SupportRealtime::teamChannel($this->company->id), SupportRealtime::adminChannel($this->admin->id)]);
    $this->postJson('http://a.localhost/admin/support/realtime/auth', ['socket_id' => '1.2', 'channel_name' => SupportRealtime::platformChannel()])->assertForbidden();
    $this->actingAs($this->owner, 'tenant_admin')->getJson('http://a.localhost/admin/support/realtime')->assertForbidden();
});

it('denies unavailable user and tenant access and keeps unconfigured installs on fallback', function () {
    $url = 'http://a.localhost/api/v1/support/realtime';
    config(['support-realtime.enabled' => false]);
    $this->actingAs($this->customer, 'tenant_user')->getJson($url)->assertExactJson(['enabled' => false]);
    $this->postJson($url.'/auth', ['socket_id' => '1.2', 'channel_name' => SupportRealtime::userChannel($this->company->id, $this->customer->id)])->assertStatus(503);
    $this->customer->update(['status' => 'SUSPENDED']);
    $this->getJson($url)->assertForbidden();
});

it('publishes only after a successful transaction and drops rolled-back notifications', function () {
    $broadcaster = Mockery::mock(Broadcaster::class);
    Broadcast::shouldReceive('connection')->once()->with('reverb')->andReturn($broadcaster);
    $broadcaster->shouldReceive('broadcast')->once()->with([
        SupportRealtime::userChannel($this->company->id, $this->customer->id),
        SupportRealtime::teamChannel($this->company->id), SupportRealtime::platformChannel(),
    ], 'support.changed', ['refresh' => true]);
    try {
        DB::transaction(function () {
            app(SupportRealtime::class)->conversation($this->company->id, $this->customer->id);
            throw new RuntimeException('Synthetic rollback');
        });
    } catch (RuntimeException) {
    }
    DB::transaction(fn () => app(SupportRealtime::class)->conversation($this->company->id, $this->customer->id));
});

it('preserves committed messages and idempotent retries when realtime publication fails', function () {
    $broadcaster = Mockery::mock(Broadcaster::class);
    Broadcast::shouldReceive('connection')->andReturn($broadcaster);
    $broadcaster->shouldReceive('broadcast')->andThrow(new RuntimeException('Synthetic offline socket'));
    $id = (string) Str::uuid();
    $send = app(SendSupportMessageAction::class);
    $first = $send->user($this->company->id, $this->customer->id, $id, 'Offline synthetic question');
    $second = $send->user($this->company->id, $this->customer->id, $id, 'Offline synthetic question');
    expect($first)->toBe($second)->and(DB::table('support_messages')->count())->toBe(1);
});

it('authenticates native device tokens on the resolved company and rejects revoked or foreign tokens', function () {
    $token = $this->postJson('http://a.localhost/api/mobile/v1/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password'])->assertCreated()->json('token');
    $url = 'http://a.localhost/api/mobile/v1/support/realtime';
    $this->withToken($token)->getJson($url)->assertOk()->assertJsonCount(1, 'channels');
    $this->withToken($token)->getJson('http://b.localhost/api/mobile/v1/support/realtime')->assertUnauthorized();
    ConsumerDeviceToken::query()->delete();
    $this->withToken($token)->postJson($url.'/auth', ['socket_id' => '1.2', 'channel_name' => SupportRealtime::userChannel($this->company->id, $this->customer->id)])->assertUnauthorized();
});

it('accepts current assigned active domains without a manual origin list and reflects live changes', function () {
    $origins = app(SupportSocketOrigins::class);
    $before = [DB::table('ledger_entries')->count(), DB::table('support_messages')->count()];
    expect($origins->allows('https://admin.localhost'))->toBeTrue()
        ->and($origins->allows('http://a.localhost:8000'))->toBeTrue();
    $domain = TenantDomain::create([
        'hostname' => 'new-socket.example.test', 'tenant_id' => $this->company->id,
        'domain_type' => 'CUSTOM_DOMAIN', 'status' => 'ACTIVE', 'is_primary' => false,
    ]);
    expect($origins->allows('https://NEW-SOCKET.example.test'))->toBeTrue();
    $domain->update(['status' => 'DISABLED']);
    expect($origins->allows('https://new-socket.example.test'))->toBeFalse();
    $domain->update(['status' => 'ACTIVE', 'tenant_id' => null]);
    expect($origins->allows('https://new-socket.example.test'))->toBeFalse();
    $domain->update(['tenant_id' => $this->other->id]);
    expect($origins->allows('https://new-socket.example.test'))->toBeTrue();
    $this->other->update(['status' => 'SUSPENDED']);
    expect($origins->allows('https://new-socket.example.test'))->toBeFalse();
    foreach ([null, 'null', '*', 'https://unknown.example.test', 'https://evil.example/a.localhost', 'https://a.localhost/path', 'https://a.localhost?x=1', 'https://user:secret@a.localhost', 'https://a.localhost:0', 'https://a.localhost:65536', 'https://a.localhost.evil.example', 'wss://a.localhost'] as $origin) {
        expect($origins->allows($origin))->toBeFalse();
    }
    expect([DB::table('ledger_entries')->count(), DB::table('support_messages')->count()])->toBe($before);
});

it('uses the dynamic Reverb origin guard and fails closed on origin lookup errors', function () {
    // Protocol bindings are installed by reverb:start; provide its channel manager in this HTTP test.
    app()->instance(ChannelManager::class, Mockery::mock(ChannelManager::class));
    $server = app(Server::class);
    expect($server)->toBeInstanceOf(CompanySupportSocketServer::class);
    $transport = Mockery::mock(WebSocketConnection::class);
    $application = new Application('offline-app', 'offline-key', 'offline-secret', 20, 30, [], 10000);
    $connection = new Connection($transport, $application, 'https://admin.localhost');
    $guard = new ReflectionMethod($server, 'verifyOrigin');
    $guard->invoke($server, $connection);
    $denied = new class($transport, $application, null) extends Connection
    {
        public function origin(): ?string
        {
            throw new RuntimeException('Synthetic unavailable origin lookup');
        }
    };
    expect(fn () => $guard->invoke($server, $denied))->toThrow(InvalidOrigin::class);
});
