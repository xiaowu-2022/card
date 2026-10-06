<?php

use App\Application\Support\SendSupportMessageAction;
use App\Application\Support\SupportChatQuery;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Support\Models\SupportConversation;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    Storage::fake('private');
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false]);
    $this->company = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->otherCompany = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $this->customer = User::where('tenant_id', $this->company->id)->firstOrFail();
    $this->otherCustomer = User::where('tenant_id', $this->otherCompany->id)->firstOrFail();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->agent = AdminUser::where('email', 'owner@a.localhost')->firstOrFail();
    $this->action = app(SendSupportMessageAction::class);
    $this->url = "http://admin.localhost/platform/tenants/{$this->company->id}/support/users/{$this->customer->id}";
    $this->actingAs($this->owner, 'platform_admin');
});

it('opens scoped new conversations read-only and proactively sends with server identity and immutable nickname', function () {
    $ledger = DB::table('ledger_entries')->count();
    $this->get($this->url)->assertOk()->assertInertia(fn ($p) => $p->where('chat.id', null));
    expect(SupportConversation::count())->toBe(0);
    $this->post('http://admin.localhost/platform/support/profile', ['support_name' => '<b>小林</b>'])->assertRedirect();
    $request = ['request_id' => (string) Str::uuid(), 'support_message' => 'Synthetic support greeting', 'support_name' => 'forged', 'sender_admin_id' => $this->agent->id, 'tenant_id' => $this->otherCompany->id];
    $this->post($this->url.'/messages', $request)->assertRedirect();
    $this->post('http://admin.localhost/platform/support/profile', ['support_name' => '小陈'])->assertRedirect();
    $this->post($this->url.'/messages', $request)->assertRedirect();
    expect(SupportMessage::count())->toBe(1);
    $this->post($this->url.'/messages', ['request_id' => (string) Str::uuid(), 'support_message' => 'Second reply'])->assertRedirect();
    $chat = app(SupportChatQuery::class)->user($this->company->id, $this->customer->id);
    expect(array_column($chat['messages'], 'supportName'))->toBe(['<b>小林</b>', '小陈'])
        ->and(array_keys($chat['messages'][0]))->toBe(['id', 'sequence', 'fromSupport', 'senderKind', 'supportName', 'text', 'deleted', 'edited', 'messageRevision', 'createdAt', 'imageSources', 'imageUrl'])
        ->and(SupportMessage::first()->sender_admin_id)->toBe($this->owner->id)
        ->and(SupportConversation::count())->toBe(1)->and(DB::table('ledger_entries')->count())->toBe($ledger);
    $this->actingAs($this->customer, 'tenant_user')->getJson('http://a.localhost/messages/unread-count')->assertJson(['count' => 0, 'supportCount' => 2]);
    $this->get('http://a.localhost/support')->assertOk();
    $this->action->platform($this->company->id, $this->owner->id, $this->customer->id, (string) Str::uuid(), 'Concurrent new reply');
    $this->postJson('http://a.localhost/support/read', ['through' => 2])->assertNoContent();
    $this->postJson('http://a.localhost/support/read', ['through' => 1])->assertNoContent();
    $this->getJson('http://a.localhost/messages/unread-count')->assertJson(['count' => 0, 'supportCount' => 1]);
});

it('shares company conversation and images without relaxing company authorization', function () {
    $id = $this->action->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'Synthetic question');
    $this->post($this->url.'/messages', ['request_id' => (string) Str::uuid(), 'support_image' => kycTestImage()])->assertRedirect();
    $message = SupportMessage::whereNotNull('image_mime')->sole();
    $this->get("http://admin.localhost/platform/tenants/{$this->company->id}/support/images/{$message->id}")->assertOk()->assertContent(kycTestImage()->get());
    $this->get("http://admin.localhost/platform/tenants/{$this->otherCompany->id}/support/images/{$message->id}")->assertNotFound();
    $this->actingAs($this->owner, 'tenant_admin')->get('http://a.localhost/admin/support/'.$id)->assertForbidden();
    $this->actingAs($this->agent, 'tenant_admin')->get('http://a.localhost/admin/support/'.$id)->assertOk();
    $this->post('http://a.localhost/admin/support/profile', ['support_name' => '公司客服'])->assertRedirect();
    $this->action->admin($this->company->id, $this->agent->id, $id, (string) Str::uuid(), 'Company reply');
    expect(SupportConversation::count())->toBe(1)->and(SupportMessage::orderByDesc('sequence')->first()->support_name)->toBe('公司客服');
    $b = AdminUser::where('email', 'owner@b.localhost')->firstOrFail();
    $this->actingAs($b, 'tenant_admin')->get('http://b.localhost/admin/support/'.$id)->assertNotFound();
});

it('filters all-company conversations with stable thirty-row pages and scoped customer search', function () {
    for ($i = 0; $i < 31; $i++) {
        $u = $this->customer->replicate(['account_id']);
        $u->forceFill(['email' => "support-$i@example.test"])->save();
        $this->action->user($this->company->id, $u->id, (string) Str::uuid(), 'Question');
    }
    $this->action->platform($this->otherCompany->id, $this->owner->id, $this->otherCustomer->id, (string) Str::uuid(), 'Proactive');
    $this->get('http://admin.localhost/platform/support')->assertOk()->assertInertia(fn ($p) => $p->has('inbox.data', 30)->where('inbox.total', 32));
    $this->get('http://admin.localhost/platform/support?page=2')->assertOk()->assertInertia(fn ($p) => $p->has('inbox.data', 2));
    $this->get('http://admin.localhost/platform/support?status=replied')->assertOk()->assertInertia(fn ($p) => $p->has('inbox.data', 1)->where('inbox.data.0.tenantId', $this->otherCompany->id));
    $this->get('http://admin.localhost/platform/support?company='.$this->company->id.'&status=awaiting&search=support-30@')->assertOk()->assertInertia(fn ($p) => $p->has('inbox.data', 1)->where('inbox.data.0.email', 'support-30@example.test'));
    $this->getJson("http://admin.localhost/platform/tenants/{$this->company->id}/support/candidates?search=".$this->otherCustomer->email)->assertOk()->assertJsonCount(0, 'users');
    $this->getJson("http://admin.localhost/platform/tenants/{$this->company->id}/support/candidates?search=".$this->customer->account_id)->assertJsonPath('users.0.id', $this->customer->id);
    $forged = "http://admin.localhost/platform/tenants/{$this->otherCompany->id}/support/users/{$this->customer->id}";
    $this->get($forged)->assertNotFound();
    $this->post($forged.'/messages', ['request_id' => (string) Str::uuid(), 'support_message' => 'wrong scope'])->assertNotFound();
});

it('separates reading sending and nickname management and blocks inactive admins', function () {
    $role = Role::where('name', 'PLATFORM_OWNER')->firstOrFail();
    $send = DB::table('permissions')->where('name', 'support.send')->value('id');
    $manage = DB::table('permissions')->where('name', 'support.agents.manage')->value('id');
    DB::table('role_permissions')->where('role_id', $role->id)->whereIn('permission_id', [$send, $manage])->delete();
    $this->get($this->url)->assertOk();
    $this->post($this->url.'/messages', ['request_id' => (string) Str::uuid(), 'support_message' => 'no permission'])->assertForbidden();
    $this->post('http://admin.localhost/platform/support/profile', ['support_name' => 'denied'])->assertForbidden();
    $this->get('http://admin.localhost/platform/support/agents')->assertForbidden();
    DB::table('role_permissions')->insert(['role_id' => $role->id, 'permission_id' => $send]);
    $this->owner->update(['status' => 'SUSPENDED']);
    $this->get($this->url)->assertForbidden();
    $this->actingAs($this->owner, 'platform_admin')->post($this->url.'/messages', ['request_id' => (string) Str::uuid(), 'support_message' => 'inactive'])->assertForbidden();
    $this->actingAs($this->agent, 'platform_admin')->get('http://admin.localhost/platform/support')->assertForbidden();
    expect(SupportConversation::count())->toBe(0);
});

it('audits administrator nickname changes validates length and preserves generic old messages', function () {
    $id = $this->action->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'Question');
    $this->action->admin($this->company->id, $this->agent->id, $id, (string) Str::uuid(), 'Old reply');
    $url = 'http://admin.localhost/platform/support/agents/'.$this->agent->id;
    $this->post($url, ['support_name' => str_repeat('中', 31)])->assertSessionHasErrors('support_name');
    $this->post($url, ['support_name' => '指定昵称'])->assertRedirect();
    $this->action->admin($this->company->id, $this->agent->id, $id, (string) Str::uuid(), 'New reply');
    $this->post($url, ['support_name' => ''])->assertRedirect();
    $this->action->admin($this->company->id, $this->agent->id, $id, (string) Str::uuid(), 'Generic reply');
    expect(array_column(app(SupportChatQuery::class)->user($this->company->id, $this->customer->id)['messages'], 'supportName'))->toBe([null, null, '指定昵称', null]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'SUPPORT_NAME_UPDATED', 'actor_id' => $this->owner->id, 'resource_id' => $this->agent->id]);
    $this->get('http://admin.localhost/platform/support/agents?search='.$this->agent->email)->assertOk()->assertInertia(fn ($p) => $p->has('agents.data', 1)->where('agents.data.0.id', $this->agent->id));
});

it('retains first-send retry intent after rollback and rejects inactive customers', function () {
    $dispatcher = SupportMessage::getEventDispatcher();
    SupportMessage::setEventDispatcher(clone $dispatcher);
    $request = (string) Str::uuid();
    try {
        SupportMessage::created(fn () => throw new RuntimeException('isolated rollback'));
        expect(fn () => $this->action->platform($this->company->id, $this->owner->id, $this->customer->id, $request, 'Retry', kycTestImage()))->toThrow(RuntimeException::class);
    } finally {
        SupportMessage::setEventDispatcher($dispatcher);
    }
    expect(SupportConversation::count())->toBe(0)->and(Storage::disk('private')->allFiles('support'))->toHaveCount(0);
    $this->action->platform($this->company->id, $this->owner->id, $this->customer->id, $request, 'Retry', kycTestImage());
    $this->action->platform($this->company->id, $this->owner->id, $this->customer->id, $request, 'Retry', kycTestImage());
    expect(SupportMessage::count())->toBe(1);
    $this->customer->update(['status' => 'SUSPENDED']);
    $this->post($this->url.'/messages', ['request_id' => (string) Str::uuid(), 'support_message' => 'inactive'])->assertForbidden();
});

it('requires read permission even when send exists and bounds Platform history to fifty messages', function () {
    for ($i = 1; $i <= 51; $i++) {
        $this->action->platform($this->company->id, $this->owner->id, $this->customer->id, (string) Str::uuid(), 'Reply '.$i);
    }
    $this->get($this->url)->assertOk()->assertInertia(fn ($p) => $p->has('chat.messages', 50)->where('chat.olderCursor', 2));
    $this->get($this->url.'?before=2')->assertOk()->assertInertia(fn ($p) => $p->has('chat.messages', 1)->where('chat.messages.0.sequence', 1));
    DB::table('role_permissions')->where('role_id', Role::where('name', 'PLATFORM_OWNER')->value('id'))
        ->where('permission_id', DB::table('permissions')->where('name', 'support.read')->value('id'))->delete();
    $this->post($this->url.'/messages', ['request_id' => (string) Str::uuid(), 'support_message' => 'no read'])->assertForbidden();
});

it('serializes simultaneous proactive first sends and duplicate requests in the isolated database', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required');
    }
    if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'card_ui_test' || DB::transactionLevel() !== 1) {
        throw new RuntimeException('Concurrency test requires isolated card_ui_test.');
    }
    $tenant = $this->company->id;
    $user = $this->customer->id;
    $admin = $this->owner->id;
    $request = (string) Str::uuid();
    DB::commit();
    RefreshDatabaseState::$migrated = false;
    DB::disconnect();
    $directory = sys_get_temp_dir().'/support-race-'.Str::uuid();
    mkdir($directory, 0700);
    $children = [];
    for ($i = 0; $i < 3; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not fork');
        }
        if ($pid === 0) {
            DB::purge();
            while (! is_file($directory.'/go')) {
                usleep(1000);
            }
            try {
                app(SendSupportMessageAction::class)->platform($tenant, $admin, $user, $i < 2 ? $request : (string) Str::uuid(), 'Concurrent greeting');
                file_put_contents($directory.'/'.$i, 'ok');
            } catch (Throwable $e) {
                file_put_contents($directory.'/'.$i, get_class($e));
            }
            exit(0);
        }
        $children[] = $pid;
    }
    touch($directory.'/go');
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }
    DB::reconnect();
    DB::beginTransaction();
    $results = [];
    for ($i = 0; $i < 3; $i++) {
        $results[] = file_get_contents($directory.'/'.$i);
        unlink($directory.'/'.$i);
    }
    unlink($directory.'/go');
    rmdir($directory);
    expect($results)->toBe(['ok', 'ok', 'ok'])->and(SupportConversation::count())->toBe(1)
        ->and(SupportMessage::count())->toBe(2)->and(SupportConversation::sole()->last_sequence)->toBe(2);
});
