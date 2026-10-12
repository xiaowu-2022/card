<?php

use App\Application\Support\SendSupportMessageAction;
use App\Application\Support\SupportAdminUnread;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Support\Models\SupportConversation;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    Http::preventStrayRequests();
    Storage::fake('private');
    config(['inertia.ssr.enabled' => false]);
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $this->customer = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->otherCustomer = User::where('tenant_id', $this->other->id)->firstOrFail();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->admin = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    $this->action = app(SendSupportMessageAction::class);
    $this->unread = app(SupportAdminUnread::class);
});

it('counts scoped customer messages and acknowledges only each administrator delivered sequence', function () {
    $request = (string) Str::uuid();
    $this->action->user($this->tenant->id, $this->customer->id, $request, 'Offline customer fixture');
    $this->action->user($this->tenant->id, $this->customer->id, $request, 'Offline customer fixture');
    $this->action->user($this->other->id, $this->otherCustomer->id, (string) Str::uuid(), 'Other company fixture');
    expect($this->unread->count($this->owner->id))->toBe(2)
        ->and($this->unread->count($this->admin->id, $this->tenant->id))->toBe(1);
    $this->actingAs($this->owner, 'platform_admin');
    $url = "http://admin.localhost/platform/tenants/{$this->tenant->id}/support/users/{$this->customer->id}";
    $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->where('inbox.data.0.unreadCount', 1));
    expect(DB::table('support_admin_reads')->count())->toBe(0);
    $this->postJson($url.'/read', ['through' => 999])->assertUnprocessable();
    $this->postJson($url.'/read', ['through' => 1])->assertNoContent();
    $this->postJson($url.'/read', ['through' => 0])->assertNoContent();
    expect($this->unread->count($this->owner->id))->toBe(1)
        ->and($this->unread->count($this->admin->id, $this->tenant->id))->toBe(1)
        ->and(DB::table('support_admin_reads')->value('through_sequence'))->toBe(1)
        ->and(SupportConversation::where('tenant_id', $this->tenant->id)->value('user_read_sequence'))->toBe(0);
    $this->action->user($this->tenant->id, $this->customer->id, (string) Str::uuid(), 'Later unread fixture');
    expect($this->unread->count($this->owner->id))->toBe(2);
    $this->getJson('http://admin.localhost/platform/support/unread')->assertOk()->assertJson(['count' => 2]);
});

it('rejects cross-company acknowledgements and inactive administrators', function () {
    $this->action->user($this->other->id, $this->otherCustomer->id, (string) Str::uuid(), 'Other company fixture');
    $conversation = SupportConversation::where('tenant_id', $this->other->id)->firstOrFail();
    $this->actingAs($this->admin, 'tenant_admin');
    $this->getJson('http://a.localhost/admin/support/unread')->assertOk()->assertJson(['count' => 0]);
    $this->postJson("http://a.localhost/admin/support/{$conversation->id}/read", ['through' => 1])->assertNotFound();
    expect(DB::table('support_admin_reads')->count())->toBe(0);
    $this->admin->forceFill(['status' => 'SUSPENDED'])->save();
    $this->getJson('http://a.localhost/admin/support/unread')->assertStatus(403);
});

it('shows company inbox badges and clears them on visible-thread POST without treating reading as replying', function () {
    $this->action->user($this->tenant->id, $this->customer->id, (string) Str::uuid(), 'Company-only unread fixture');
    $conversation = SupportConversation::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->actingAs($this->admin, 'tenant_admin');
    $this->get('http://a.localhost/admin/support')->assertOk()->assertInertia(fn ($p) => $p->where('inbox.items.0.unreadCount', 1));
    $this->get("http://a.localhost/admin/support/{$conversation->id}")->assertOk();
    expect(DB::table('support_admin_reads')->count())->toBe(0);
    $this->postJson("http://a.localhost/admin/support/{$conversation->id}/read", ['through' => 1])->assertNoContent();
    $this->getJson('http://a.localhost/admin/support/unread')->assertOk()->assertJson(['count' => 0]);
    $this->get('http://a.localhost/admin/support')->assertOk()->assertInertia(fn ($p) => $p->where('inbox.items.0.unreadCount', 0)->where('inbox.items.0.awaitingReply', true));
    expect($this->unread->count($this->owner->id))->toBe(1);
});

it('sorts platform unread conversations first across pagination while retaining scoped filters and read state', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 01:00:00', 'UTC'));
    $this->action->user($this->tenant->id, $this->customer->id, (string) Str::uuid(), 'Older unread fixture');
    $older = SupportConversation::where('tenant_id', $this->tenant->id)->where('user_id', $this->customer->id)->firstOrFail();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'));
    $this->action->user($this->other->id, $this->otherCustomer->id, (string) Str::uuid(), 'Other company unread fixture');
    $other = SupportConversation::where('tenant_id', $this->other->id)->firstOrFail();
    for ($i = 0; $i < 32; $i++) {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 03:00:00', 'UTC')->addMinutes($i));
        $user = $this->customer->replicate(['account_id']);
        $user->forceFill(['email' => Str::uuid().'@example.test'])->save();
        $this->action->user($this->tenant->id, $user->id, (string) Str::uuid(), 'Newer read fixture');
        $conversation = SupportConversation::where('user_id', $user->id)->firstOrFail();
        $this->unread->read($this->owner->id, $this->tenant->id, $conversation->id, 1, true);
    }
    $before = collect(['support_messages', 'support_admin_reads', 'support_conversations'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy($table === 'support_admin_reads' ? 'conversation_id' : 'id')->get()->toJson()]);
    $this->actingAs($this->owner, 'platform_admin');
    $url = 'http://admin.localhost/platform/support';
    $first = $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->where('inbox.total', 34)->where('inbox.last_page', 2)
        ->where('inbox.data.0.id', $other->id)->where('inbox.data.0.unreadCount', 1)
        ->where('inbox.data.1.id', $older->id)->where('inbox.data.1.unreadCount', 1)->where('inbox.data.2.unreadCount', 0));
    $second = $this->get($url.'?page=2')->assertOk()->assertInertia(fn ($p) => $p->has('inbox.data', 4)->where('inbox.data.0.unreadCount', 0));
    expect(array_intersect(array_column($first->viewData('page')['props']['inbox']['data'], 'id'), array_column($second->viewData('page')['props']['inbox']['data'], 'id')))->toBe([]);
    $this->get($url.'?company='.$this->tenant->id)->assertOk()->assertInertia(fn ($p) => $p->where('inbox.total', 33)->where('inbox.data.0.id', $older->id));
    foreach ($before as $table => $json) {
        expect(DB::table($table)->orderBy($table === 'support_admin_reads' ? 'conversation_id' : 'id')->get()->toJson())->toBe($json);
    }
    $this->unread->read($this->owner->id, $this->tenant->id, $older->id, 1, true);
    $this->get($url.'?page=2')->assertOk()->assertInertia(fn ($p) => $p->where('inbox.data.3.id', $older->id)->where('inbox.data.3.unreadCount', 0));
    Http::assertNothingSent();
});
