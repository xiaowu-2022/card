<?php

use App\Application\Inbox\BroadcastMessages;
use App\Application\Inbox\InboxDelivery;
use App\Application\Inbox\InboxQuery;
use App\Application\Inbox\InboxWriter;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
});

function inboxEvent($test, string $key = 'test:1'): string
{
    DB::transaction(fn () => app(InboxWriter::class)->record($test->tenant->id, $test->user->id, $key, 'deposit', ['amount' => '0.000000000000000001', 'asset' => 'ETH'], '/funds'));

    return DB::table('inbox_events')->where('tenant_id', $test->tenant->id)->where('user_id', $test->user->id)->where('event_key', $key)->value('id');
}

it('atomically records intents, rolls back, and delivers exactly once with exact money', function () {
    $before = DB::table('inbox_events')->count();
    try {
        DB::transaction(function () {
            inboxEvent($this, 'rollback');
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect(DB::table('inbox_events')->count())->toBe($before);
    $id = inboxEvent($this);
    inboxEvent($this);
    $delivery = app(InboxDelivery::class);
    expect($delivery->attempt($this->tenant->id, $id))->toBeTrue();
    expect($delivery->attempt($this->tenant->id, $id))->toBeTrue();
    expect(DB::table('inbox_receipts')->where('event_id', $id)->count())->toBe(1);
    expect(app(InboxQuery::class)->detail($this->tenant->id, $this->user->id, $id)['parameters']['amount'])->toBe('0.000000000000000001');
});

it('requires a business transaction and allowlisted templates', function () {
    $manager = DB::getFacadeRoot();
    try {
        DB::partialMock()->shouldReceive('transactionLevel')->andReturn(0);
        expect(fn () => app(InboxWriter::class)->record($this->tenant->id, $this->user->id, 'bad', 'deposit'))->toThrow(LogicException::class);
    } finally {
        DB::swap($manager);
    }
    expect(fn () => DB::transaction(fn () => app(InboxWriter::class)->record($this->tenant->id, $this->user->id, 'bad', 'unknown')))->toThrow(LogicException::class);
});

it('keeps GET read-only, marks one message and leaves new arrivals unread after read-all', function () {
    $id = inboxEvent($this);
    app(InboxDelivery::class)->attempt($this->tenant->id, $id);
    $this->actingAs($this->user, 'tenant_user');
    $this->get('http://a.localhost/messages/'.$id)->assertOk()->assertInertia(fn ($p) => $p->component('user/Message')->where('message.readAt', null));
    $this->getJson('http://a.localhost/messages/unread-count')->assertOk()->assertJson(['count' => 1]);
    $this->post('http://a.localhost/messages/'.$id.'/read')->assertRedirect();
    $first = DB::table('inbox_receipts')->where('event_id', $id)->value('read_at');
    $this->post('http://a.localhost/messages/'.$id.'/read')->assertRedirect();
    expect(DB::table('inbox_receipts')->where('event_id', $id)->value('read_at'))->toBe($first);
    $this->post('http://a.localhost/messages/read-all')->assertRedirect();
    $new = inboxEvent($this, 'next');
    app(InboxDelivery::class)->attempt($this->tenant->id, $new);
    $this->getJson('http://a.localhost/messages/unread-count')->assertJson(['count' => 1]);
    $this->get('http://a.localhost/messages?filter=unread')->assertOk()->assertInertia(fn ($p) => $p->has('messages.data', 1)->where('messages.data.0.id', $new));
});

it('scopes reads and receipts to both the user and tenant and permits suspended users', function () {
    $id = inboxEvent($this);
    app(InboxDelivery::class)->attempt($this->tenant->id, $id);
    $other = User::where('tenant_id', '!=', $this->tenant->id)->firstOrFail();
    $this->actingAs($other, 'tenant_user');
    $this->get('http://b.localhost/messages/'.$id)->assertNotFound();
    $this->post('http://b.localhost/messages/'.$id.'/read')->assertNotFound();
    $this->user->update(['status' => 'SUSPENDED']);
    $this->actingAs($this->user->refresh(), 'tenant_user');
    $this->get('http://a.localhost/messages/'.$id)->assertOk();
});

it('requires authentication for inbox access', function () {
    $this->get('http://a.localhost/messages')->assertRedirect('/login');
});

it('sends an immutable recipient snapshot, retries safely and reports delivery and reads', function () {
    $data = ['title' => '<b>Notice</b>', 'body' => 'Plain text <script>alert(1)</script>', 'audience' => 'all', 'users' => []];
    $service = app(BroadcastMessages::class);
    $preview = $service->preview($this->owner, $this->tenant->id, $data);
    $id = (string) Str::uuid();
    $intent = $data + ['request_id' => $id, 'token' => $preview['token'], 'confirmed' => true];
    $service->send($this->owner, $this->tenant->id, $intent);
    $service->send($this->owner, $this->tenant->id, $intent);
    $late = User::create(['tenant_id' => $this->tenant->id, 'email' => 'after-broadcast@example.test', 'password_hash' => bcrypt('test-password'), 'status' => 'ACTIVE']);
    expect(DB::table('inbox_events')->where('broadcast_id', $id)->count())->toBe($preview['count']);
    expect(DB::table('inbox_events')->where('broadcast_id', $id)->where('user_id', $late->id)->count())->toBe(0);
    expect(DB::table('inbox_events')->where('broadcast_id', $id)->where('tenant_id', '!=', $this->tenant->id)->count())->toBe(0);
    app(InboxDelivery::class)->recover($this->tenant->id);
    app(InboxDelivery::class)->recover($this->tenant->id);
    expect(DB::table('inbox_receipts')->whereIn('event_id', DB::table('inbox_events')->where('broadcast_id', $id)->select('id'))->count())->toBe($preview['count']);
    $this->actingAs($this->owner, 'platform_admin')->get('http://admin.localhost/platform/notifications?company='.$this->tenant->id)
        ->assertOk()->assertInertia(fn ($p) => $p->component('platform/Notifications')->where('batches.data.0.title', $data['title'])->where('batches.data.0.delivered', $preview['count']));
});

it('rejects changed content, cross-company recipients, and unprivileged senders', function () {
    $service = app(BroadcastMessages::class);
    $data = ['title' => 'Notice', 'body' => 'Hello', 'audience' => 'selected', 'users' => [$this->user->id]];
    $preview = $service->preview($this->owner, $this->tenant->id, $data);
    $changed = array_replace($data, ['body' => 'Different', 'request_id' => (string) Str::uuid(), 'token' => $preview['token'], 'confirmed' => true]);
    expect(fn () => $service->send($this->owner, $this->tenant->id, $changed))->toThrow(ValidationException::class);
    $other = User::where('tenant_id', '!=', $this->tenant->id)->firstOrFail();
    expect(fn () => $service->preview($this->owner, $this->tenant->id, array_replace($data, ['users' => [$other->id]])))->toThrow(ValidationException::class);
    $admin = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    $this->actingAs($admin, 'platform_admin')->postJson('http://admin.localhost/platform/tenants/'.$this->tenant->id.'/notifications/preview', $data)->assertForbidden();
});

it('revalidates a changed all-user audience before confirmation', function () {
    $service = app(BroadcastMessages::class);
    $data = ['title' => 'Notice', 'body' => 'Hello', 'audience' => 'all', 'users' => []];
    $preview = $service->preview($this->owner, $this->tenant->id, $data);
    User::create(['tenant_id' => $this->tenant->id, 'email' => 'new-inbox@example.test', 'password_hash' => bcrypt('test-password'), 'status' => 'ACTIVE']);
    expect(fn () => $service->send($this->owner, $this->tenant->id, $data + ['request_id' => (string) Str::uuid(), 'token' => $preview['token'], 'confirmed' => true]))->toThrow(ValidationException::class);
});

it('recovers partial delivery failure without touching financial records', function () {
    $ledger = DB::table('ledger_entries')->count();
    DB::unprepared("CREATE OR REPLACE FUNCTION fail_test_inbox() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'test delivery failure'; END \$\$; CREATE TRIGGER fail_test_inbox BEFORE INSERT ON inbox_receipts FOR EACH ROW EXECUTE FUNCTION fail_test_inbox();");
    $id = inboxEvent($this, 'recover');
    try {
        expect(app(InboxDelivery::class)->attempt($this->tenant->id, $id))->toBeFalse();
        expect(DB::table('inbox_receipts')->where('event_id', $id)->count())->toBe(0);
        expect(DB::table('inbox_events')->where('id', $id)->value('delivered_at'))->toBeNull();
    } finally {
        DB::unprepared('DROP TRIGGER fail_test_inbox ON inbox_receipts; DROP FUNCTION fail_test_inbox()');
    }
    app(InboxDelivery::class)->recover($this->tenant->id);
    expect(DB::table('inbox_receipts')->where('event_id', $id)->count())->toBe(1);
    expect(DB::table('ledger_entries')->count())->toBe($ledger);
});

it('does not mark an arrival between the read-all watermark and the update', function () {
    $first = inboxEvent($this, 'before-cutoff');
    app(InboxDelivery::class)->attempt($this->tenant->id, $first);
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $connection->setEventDispatcher(clone $dispatcher);
    $new = null;
    DB::listen(function ($event) use (&$new) {
        if ($new === null && str_contains($event->sql, 'max("id")')) {
            $new = 'creating';
            $new = inboxEvent($this, 'concurrent-arrival');
            app(InboxDelivery::class)->attempt($this->tenant->id, $new);
        }
    });
    try {
        app(InboxQuery::class)->read($this->tenant->id, $this->user->id, null);
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }
    expect($new)->not->toBeNull();
    expect(DB::table('inbox_receipts')->where('event_id', $first)->value('read_at'))->not->toBeNull();
    expect(DB::table('inbox_receipts')->where('event_id', $new)->value('read_at'))->toBeNull();
});

it('paginates twenty messages and isolates same-company recipients', function () {
    for ($i = 0; $i < 21; $i++) {
        $id = inboxEvent($this, 'page:'.$i);
        app(InboxDelivery::class)->attempt($this->tenant->id, $id);
    }
    $this->actingAs($this->user, 'tenant_user');
    $this->get('http://a.localhost/messages?filter=business')->assertOk()->assertInertia(fn ($p) => $p->has('messages.data', 20)->where('messages.total', 21));
    $this->get('http://a.localhost/messages?page=2')->assertOk()->assertInertia(fn ($p) => $p->has('messages.data', 1));
    $this->get('http://a.localhost/messages?filter=platform')->assertOk()->assertInertia(fn ($p) => $p->has('messages.data', 0));
    $other = User::create(['tenant_id' => $this->tenant->id, 'email' => 'another-inbox@example.test', 'password_hash' => bcrypt('test-password'), 'status' => 'ACTIVE']);
    $this->actingAs($other, 'tenant_user');
    $this->get('http://a.localhost/messages/'.$id)->assertNotFound();
    $this->post('http://a.localhost/messages/'.$id.'/read')->assertNotFound();
    $this->getJson('http://a.localhost/messages/unread-count')->assertJson(['count' => 0]);
});
