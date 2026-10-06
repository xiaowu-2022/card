<?php

use App\Application\Support\SendSupportMessageAction;
use App\Application\Support\SupportBot;
use App\Application\Support\SupportQuickReplies;
use App\Application\Support\SupportUnread;
use App\Application\Support\SupportUserAgents;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Support\Models\SupportConversation;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
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
    $this->company = Tenant::where('slug', 'tenant-a')->sole();
    $this->other = Tenant::where('slug', 'tenant-b')->sole();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->sole();
    $this->customer = User::where('tenant_id', $this->company->id)->firstOrFail();
    $this->agent = $this->customer->replicate(['account_id']);
    $this->agent->forceFill(['email' => 'agent@example.test'])->save();
    $this->second = $this->customer->replicate(['account_id']);
    $this->second->forceFill(['email' => 'second-agent@example.test'])->save();
    $this->agents = app(SupportUserAgents::class);
    $this->sender = app(SendSupportMessageAction::class);
    $this->url = 'http://a.localhost/api/v1/support-workspace';
    $this->grantUrl = 'http://admin.localhost/platform/tenants/'.$this->company->id.'/users/'.$this->agent->id.'/support-agent';
});

it('grants existing users without admin identities and retires independent login routes', function () {
    $admins = AdminUser::count();
    $this->actingAs($this->owner, 'platform_admin')->post($this->grantUrl, ['enabled' => true, 'revision' => 0])->assertRedirect();
    $this->postJson($this->grantUrl, ['enabled' => false, 'revision' => 0])->assertConflict();
    $this->get('http://admin.localhost/platform/users?support=Enabled')->assertOk()->assertInertia(fn ($p) => $p->where('users.total', 1)->where('users.data.0.supportAgent', true));
    expect(AdminUser::count())->toBe($admins);
    $this->actingAs($this->agent, 'tenant_user')->getJson('http://a.localhost/api/v1/account')->assertOk()->assertJsonPath('supportAgent', true);
    $this->getJson($this->url)->assertOk()->assertJsonPath('inbox.total', 0);
    $this->get('http://admin.localhost/support-agent/login')->assertNotFound();
    $this->post('http://admin.localhost/support-agent/login', ['email' => $this->agent->email, 'password' => 'anything'])->assertNotFound();
    $this->get('http://admin.localhost/platform/support/accounts/'.$this->owner->id)->assertNotFound();
});

it('rejects ordinary users and foreign company grants and views without writes', function () {
    $this->actingAs($this->agent, 'tenant_user')->getJson($this->url)->assertForbidden();
    $this->postJson($this->url.'/replies', ['id' => (string) Str::uuid(), 'title' => 'x', 'body' => 'x', 'revision' => 0, 'archived' => false])->assertForbidden();
    $otherUser = User::where('tenant_id', $this->other->id)->firstOrFail();
    $this->actingAs($this->owner, 'platform_admin')->postJson(str_replace($this->agent->id, $otherUser->id, $this->grantUrl), ['enabled' => true, 'revision' => 0])->assertNotFound();
    expect(DB::table('support_user_agents')->count())->toBe(0)->and(SupportMessage::count())->toBe(0);
});

it('allows two assigned users to share a queue but blocks foreign and own consultations and images', function () {
    foreach ([$this->agent, $this->second] as $user) {
        $this->agents->grant($this->company->id, $user->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    }
    $id = $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'question', kycTestImage());
    $own = $this->sender->user($this->company->id, $this->agent->id, (string) Str::uuid(), 'my personal question');
    $otherUser = User::where('tenant_id', $this->other->id)->firstOrFail();
    $foreign = $this->sender->user($this->other->id, $otherUser->id, (string) Str::uuid(), 'other company', kycTestImage());
    $foreignImage = SupportMessage::where('conversation_id', $foreign)->sole()->id;
    $this->actingAs($this->agent, 'tenant_user')->getJson($this->url)->assertJsonPath('inbox.total', 1)->assertJsonPath('awaitingCount', 1);
    $this->getJson($this->url.'?status=BOT&search=not-found&page=2')->assertJsonPath('inbox.total', 0)->assertJsonPath('awaitingCount', 1);
    $this->getJson($this->url.'/conversations/'.$own)->assertNotFound();
    $this->getJson($this->url.'/conversations/'.$foreign)->assertNotFound();
    $this->getJson($this->url.'/images/'.$foreignImage)->assertNotFound();
    $this->postJson($this->url.'/conversations/'.$own.'/messages', ['request_id' => (string) Str::uuid(), 'support_message' => 'forged'])->assertNotFound();
    $this->getJson($this->url.'/conversations/'.$id)->assertOk();
    $this->actingAs($this->second, 'tenant_user')->getJson($this->url)->assertJsonPath('inbox.total', 2)->assertJsonPath('awaitingCount', 2);
    $this->getJson($this->url.'/conversations/'.$id)->assertOk();
});

it('persists customer-facing agent replies with separate identity, nickname snapshots and idempotency', function () {
    foreach ([$this->agent, $this->second] as $user) {
        $this->agents->grant($this->company->id, $user->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    }
    app(SupportBot::class)->configure($this->company->id, $this->owner->id, true, 0);
    $id = $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'question');
    $this->actingAs($this->agent, 'tenant_user')->postJson($this->url.'/profile', ['support_name' => '小林', 'revision' => 1])->assertNoContent();
    $request = (string) Str::uuid();
    $body = ['request_id' => $request, 'support_message' => '人工解答'];
    $endpoint = $this->url.'/conversations/'.$id.'/messages';
    $this->postJson($endpoint, $body)->assertNoContent();
    $this->postJson($endpoint, $body)->assertNoContent();
    $this->postJson($endpoint, [...$body, 'support_message' => 'different'])->assertConflict();
    $this->postJson($this->url.'/profile', ['support_name' => '新名字', 'revision' => 2])->assertNoContent();
    $this->actingAs($this->second, 'tenant_user')->postJson($endpoint, ['request_id' => $request, 'support_message' => '另一名客服'])->assertNoContent();
    $messages = SupportMessage::whereNotNull('sender_support_user_id')->orderBy('sequence')->get();
    expect($messages)->toHaveCount(2)->and($messages[0]->sender_user_id)->toBeNull()->and($messages[0]->sender_admin_id)->toBeNull()->and($messages[0]->support_name)->toBe('小林');
    expect(SupportConversation::find($id)->mode)->toBe('HUMAN')->and(SupportConversation::find($id)->last_sender)->toBe('AGENT');
    $this->actingAs($this->customer, 'tenant_user')->getJson('http://a.localhost/api/v1/support')->assertJsonPath('messages.2.senderKind', 'SUPPORT_AGENT')->assertJsonPath('messages.2.fromSupport', true)->assertJsonMissingPath('messages.2.sender_support_user_id');
    expect(app(SupportUnread::class)->count($this->company->id, $this->customer->id))->toBe(3);
    expect(DB::table('support_messages')->where('request_id', $request)->first()->support_message)->not->toContain('人工解答');
    expect(DB::table('audit_logs')->where('action', 'SUPPORT_TAKEOVER')->value('actor_type'))->toBe('USER');
});

it('immediately revokes every workspace operation while leaving ordinary account access intact', function () {
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    $id = $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'question', kycTestImage());
    $image = SupportMessage::where('conversation_id', $id)->sole()->id;
    $this->actingAs($this->agent, 'tenant_user')->getJson($this->url)->assertOk();
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => false, 'revision' => 1]);
    foreach (['', '/replies', '/images/'.$image, '/conversations/'.$id] as $suffix) {
        $this->getJson($this->url.$suffix)->assertForbidden();
    }
    $this->postJson($this->url.'/conversations/'.$id.'/messages', ['request_id' => (string) Str::uuid(), 'support_message' => 'denied'])->assertForbidden();
    $this->getJson('http://a.localhost/api/v1/account')->assertOk()->assertJsonPath('supportAgent', false);
    $this->postJson('http://a.localhost/api/v1/support/messages', ['request_id' => (string) Str::uuid(), 'support_message' => 'ordinary question'])->assertNoContent();
});

it('scopes personal and company replies and preserves revisions without sending messages', function () {
    foreach ([$this->agent, $this->second] as $user) {
        $this->agents->grant($this->company->id, $user->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    }
    $body = ['id' => (string) Str::uuid(), 'title' => 'personal', 'body' => 'answer', 'revision' => 0, 'archived' => false];
    $this->actingAs($this->agent, 'tenant_user')->postJson($this->url.'/replies', $body)->assertNoContent();
    app(SupportQuickReplies::class)->save($this->owner->id, $this->company->id, [...$body, 'id' => (string) Str::uuid(), 'title' => 'shared']);
    $this->getJson($this->url.'/replies')->assertJsonPath('total', 2);
    $this->getJson($this->url.'/replies?personal=1')->assertJsonPath('total', 1);
    $this->actingAs($this->second, 'tenant_user')->getJson($this->url.'/replies')->assertJsonPath('total', 1);
    $this->postJson($this->url.'/replies', [...$body, 'revision' => 1])->assertNotFound();
    $this->actingAs($this->agent, 'tenant_user')->postJson($this->url.'/replies', [...$body, 'revision' => 1, 'archived' => true])->assertNoContent();
    $this->postJson($this->url.'/replies', [...$body, 'revision' => 1])->assertConflict();
    expect(SupportMessage::count())->toBe(0);
});

it('supports image replies and revision-checked finish with read-only workspace GETs', function () {
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    app(SupportBot::class)->configure($this->company->id, $this->owner->id, true, 0);
    $id = $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'question');
    $this->actingAs($this->agent, 'tenant_user')->post($this->url.'/conversations/'.$id.'/messages', ['request_id' => (string) Str::uuid(), 'support_image' => kycTestImage()])->assertNoContent();
    $message = SupportMessage::where('sender_support_user_id', $this->agent->id)->sole();
    $this->get($this->url.'/images/'.$message->id)->assertOk();
    $count = SupportMessage::count();
    $revision = SupportConversation::find($id)->revision;
    $this->getJson($this->url.'/conversations/'.$id)->assertOk();
    expect(SupportMessage::count())->toBe($count)->and(SupportConversation::find($id)->user_read_sequence)->toBe(0);
    $finish = ['request_id' => (string) Str::uuid(), 'revision' => $revision];
    $this->postJson($this->url.'/conversations/'.$id.'/finish', [...$finish, 'revision' => $revision - 1])->assertConflict();
    $this->postJson($this->url.'/conversations/'.$id.'/finish', $finish)->assertNoContent();
    $this->postJson($this->url.'/conversations/'.$id.'/finish', $finish)->assertNoContent();
    expect(SupportConversation::find($id)->mode)->toBe('BOT')->and(SupportMessage::count())->toBe($count + 1);
});

it('binds direct-upload replies to the agent and replays a claimed image only for the same message', function () {
    config(['media.storage_driver' => 'server']);
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    $id = $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'question');
    $this->actingAs($this->agent, 'tenant_user');
    $base = 'http://a.localhost/api/v1';
    $ticket = $this->postJson($base.'/images/direct', ['purpose' => 'support', 'field' => 'support_image', 'mime' => 'image/png'])->assertOk()->json();
    $this->post($base.'/images/direct/'.$ticket['id'].'/backup', ['file' => kycTestImage()])->assertNoContent();
    $this->postJson($base.'/images/direct/'.$ticket['id'].'/complete')->assertNoContent();
    $body = ['request_id' => (string) Str::uuid(), 'support_image_upload_id' => $ticket['id']];
    $endpoint = $this->url.'/conversations/'.$id.'/messages';
    $this->postJson($endpoint, $body)->assertNoContent();
    $this->postJson($endpoint, $body)->assertNoContent();
    $this->postJson($endpoint, [...$body, 'request_id' => (string) Str::uuid()])->assertConflict();
    expect(SupportMessage::whereNotNull('sender_support_user_id')->count())->toBe(1);
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => false, 'revision' => 1]);
    $this->postJson($endpoint, $body)->assertForbidden();
});

it('denies a suspended support user and validates explicit platform permissions', function () {
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    $this->agent->update(['status' => 'SUSPENDED']);
    $this->actingAs($this->agent->fresh(), 'tenant_user')->getJson($this->url)->assertForbidden();
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => false, 'revision' => 1]);
    $this->actingAs($this->owner, 'platform_admin')->postJson($this->grantUrl, ['enabled' => true, 'revision' => 2])->assertUnprocessable();
    DB::table('role_permissions')->where('permission_id', DB::table('permissions')->where('name', 'support.agents.manage')->value('id'))->delete();
    $this->postJson($this->grantUrl, ['enabled' => false, 'revision' => 2])->assertForbidden();
});

it('reports recent foreground presence without mutating reads or accepting selected identities', function () {
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    $id = $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'hello');
    $this->actingAs($this->agent, 'tenant_user')->getJson($this->url.'/conversations/'.$id)->assertJsonPath('customerOnline', false);
    expect(DB::table('consumer_presence')->count())->toBe(0);
    $this->actingAs($this->customer, 'tenant_user')->postJson('http://a.localhost/api/v1/presence', ['user_id' => $this->agent->id])->assertNoContent();
    expect(DB::table('consumer_presence')->sole()->user_id)->toBe($this->customer->id);
    $this->actingAs($this->agent, 'tenant_user')->getJson($this->url)->assertJsonPath('inbox.data.0.online', true);
    $this->getJson($this->url.'/conversations/'.$id)->assertJsonPath('customerOnline', true)->assertJsonPath('customerEmail', $this->customer->email);
    $this->travel(76)->seconds();
    $this->getJson($this->url.'/conversations/'.$id)->assertJsonPath('customerOnline', false);
});

it('revises own messages with immutable encrypted history idempotency and version-specific read receipts', function () {
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    $id = $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'hello');
    $this->sender->agent($this->company->id, $this->agent->id, $id, (string) Str::uuid(), 'original answer', kycTestImage());
    $message = SupportMessage::where('conversation_id', $id)->whereNotNull('sender_support_user_id')->sole();
    $original = DB::table('support_messages')->where('id', $message->id)->first();
    $url = $this->url.'/conversations/'.$id;
    $change = $url.'/messages/'.$message->id.'/change';
    $this->actingAs($this->agent, 'tenant_user')->getJson($url)->assertJsonPath('messages.1.canManage', true)->assertJsonPath('messages.1.readByUser', false);
    $this->actingAs($this->customer, 'tenant_user')->postJson('http://a.localhost/api/v1/support/read', ['through' => $message->sequence])->assertNoContent();
    $this->actingAs($this->agent, 'tenant_user')->getJson($url)->assertJsonPath('messages.1.readByUser', true);
    $body = ['request_id' => (string) Str::uuid(), 'revision' => 0, 'operation' => 'EDIT', 'text' => 'corrected answer'];
    $this->postJson($change, $body)->assertNoContent();
    $this->postJson($change, $body)->assertNoContent();
    $this->postJson($change, [...$body, 'text' => 'different'])->assertConflict();
    $this->postJson($change, [...$body, 'request_id' => (string) Str::uuid()])->assertConflict();
    expect(DB::table('support_message_revisions')->count())->toBe(1);
    expect(DB::table('support_message_revisions')->sole()->body)->not->toContain('corrected answer');
    expect(DB::table('support_messages')->where('id', $message->id)->first())->toEqual($original);
    $this->getJson($url)->assertJsonPath('messages.1.text', 'corrected answer')->assertJsonPath('messages.1.readByUser', false)->assertJsonPath('messages.1.messageRevision', 1);
    $this->actingAs($this->customer, 'tenant_user')->getJson('http://a.localhost/api/v1/support')->assertJsonPath('messages.1.text', 'corrected answer')->assertJsonMissingPath('messages.1.canManage')->assertJsonMissingPath('messages.1.readByUser');
    $read = ['through' => $message->sequence, 'revisions' => [['id' => $message->id, 'revision' => 1]]];
    $this->postJson('http://a.localhost/api/v1/support/read', $read)->assertNoContent();
    $this->postJson('http://a.localhost/api/v1/support/read', [...$read, 'revisions' => [['id' => $message->id, 'revision' => 99]]])->assertUnprocessable();
    $this->actingAs($this->agent, 'tenant_user')->getJson($url)->assertJsonPath('messages.1.readByUser', true);
    $delete = ['request_id' => (string) Str::uuid(), 'revision' => 1, 'operation' => 'DELETE'];
    $this->postJson($change, $delete)->assertNoContent();
    $this->postJson($change, $delete)->assertNoContent();
    $this->getJson($url)->assertJsonPath('messages.1.deleted', true)->assertJsonPath('messages.1.text', null)->assertJsonPath('messages.1.imageUrl', null)->assertJsonPath('messages.1.canManage', false);
    $this->get($this->url.'/images/'.$message->id)->assertNotFound();
    $this->postJson($change, [...$body, 'request_id' => (string) Str::uuid(), 'revision' => 2])->assertConflict();
    $this->actingAs($this->customer, 'tenant_user')->getJson('http://a.localhost/api/v1/support')->assertJsonPath('messages.1.deleted', true)->assertJsonPath('messages.1.imageSources', []);
    expect(DB::table('support_messages')->where('id', $message->id)->first())->toEqual($original);
    expect(SupportMessage::where('conversation_id', $id)->count())->toBe(2);
});

it('forbids changing customer other-agent and cross-company messages and rechecks revoked grants', function () {
    foreach ([$this->agent, $this->second] as $user) {
        $this->agents->grant($this->company->id, $user->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    }
    $id = $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'hello');
    $customerMessage = SupportMessage::where('conversation_id', $id)->sole();
    $this->sender->agent($this->company->id, $this->second->id, $id, (string) Str::uuid(), 'second agent');
    $otherMessage = SupportMessage::where('conversation_id', $id)->whereNotNull('sender_support_user_id')->sole();
    $foreignUser = User::where('tenant_id', $this->other->id)->firstOrFail();
    $foreign = $this->sender->user($this->other->id, $foreignUser->id, (string) Str::uuid(), 'foreign');
    $foreignMessage = SupportMessage::where('conversation_id', $foreign)->sole();
    $body = ['request_id' => (string) Str::uuid(), 'revision' => 0, 'operation' => 'DELETE'];
    $this->actingAs($this->agent, 'tenant_user');
    foreach ([$customerMessage, $otherMessage] as $message) {
        $this->postJson($this->url.'/conversations/'.$id.'/messages/'.$message->id.'/change', $body)->assertForbidden();
    }
    $this->postJson($this->url.'/conversations/'.$foreign.'/messages/'.$foreignMessage->id.'/change', $body)->assertNotFound();
    $this->agents->grant($this->company->id, $this->second->id, $this->owner->id, ['enabled' => false, 'revision' => 1]);
    $this->actingAs($this->second, 'tenant_user')->postJson($this->url.'/conversations/'.$id.'/messages/'.$otherMessage->id.'/change', $body)->assertForbidden();
    expect(DB::table('support_message_revisions')->count())->toBe(0);
});

it('shares all conversation states and complete multi-page history between company agents', function () {
    foreach ([$this->agent, $this->second] as $user) {
        $this->agents->grant($this->company->id, $user->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    }
    $id = $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'first customer message', kycTestImage());
    for ($n = 1; $n <= 104; $n++) {
        if ($n % 3 === 0) {
            $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'customer '.$n);
        } else {
            $actor = $n % 3 === 1 ? $this->agent : $this->second;
            $this->sender->agent($this->company->id, $actor->id, $id, (string) Str::uuid(), 'agent '.$n);
        }
    }
    $ids = SupportMessage::where('conversation_id', $id)->orderBy('sequence')->pluck('id')->all();
    $before = DB::table('support_conversations')->where('id', $id)->first();
    $transcripts = [];
    foreach ([$this->agent, $this->second] as $user) {
        $this->actingAs($user, 'tenant_user');
        // Last sender was another agent: default ALL still includes this conversation.
        $this->getJson($this->url)->assertOk()->assertJsonPath('inbox.total', 1)->assertJsonPath('inbox.data.0.id', $id)->assertJsonPath('awaitingCount', 0);
        $this->getJson($this->url.'?status=awaiting')->assertJsonPath('inbox.total', 0);
        $cursor = 0;
        $history = [];
        do {
            $response = $this->getJson($this->url.'/conversations/'.$id.'?before='.$cursor)->assertOk();
            $batch = $response->json('messages');
            $history = array_merge($batch, $history);
            $cursor = $response->json('olderCursor');
        } while ($cursor !== null);
        expect(array_column($history, 'id'))->toBe($ids);
        expect(array_column($history, 'sequence'))->toBe(range(1, 105));
        expect($history[0]['imageUrl'])->not->toBeNull();
        $this->get($this->url.'/images/'.$history[0]['id'])->assertOk();
        $transcripts[] = array_map(fn ($m) => [$m['id'], $m['text'], $m['supportName'], $m['senderKind']], $history);
    }
    expect($transcripts[0])->toBe($transcripts[1]);
    expect(DB::table('support_conversations')->where('id', $id)->first())->toEqual($before);
    expect(SupportMessage::where('conversation_id', $id)->count())->toBe(105);
    // Bot and waiting conversations also remain visible in the default shared list.
    foreach (['BOT', 'WAITING'] as $mode) {
        SupportConversation::whereKey($id)->update(['mode' => $mode]);
        $this->getJson($this->url)->assertJsonPath('inbox.total', 1)->assertJsonPath('inbox.data.0.mode', $mode);
    }
});

it('prioritizes pending company chats and counts individual unhandled customer messages', function () {
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    $pending = $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'first question');
    $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'second question');
    $handled = $this->sender->user($this->company->id, $this->second->id, (string) Str::uuid(), 'handled question');
    $this->sender->agent($this->company->id, $this->agent->id, $handled, (string) Str::uuid(), 'answer');
    SupportConversation::whereKey($pending)->update(['updated_at' => now()->subDay()]);
    $this->sender->user($this->company->id, $this->agent->id, (string) Str::uuid(), 'own consultation');
    $foreign = User::where('tenant_id', $this->other->id)->firstOrFail();
    $this->sender->user($this->other->id, $foreign->id, (string) Str::uuid(), 'foreign consultation');
    $this->actingAs($this->agent, 'tenant_user')->getJson($this->url)->assertOk()
        ->assertJsonPath('inbox.total', 2)->assertJsonPath('inbox.data.0.id', $pending)
        ->assertJsonPath('inbox.data.1.id', $handled)->assertJsonPath('awaitingCount', 1)
        ->assertJsonPath('pendingMessageCount', 2);
    $this->getJson($this->url.'?search=no-match')->assertJsonPath('inbox.total', 0)->assertJsonPath('pendingMessageCount', 2);
    $this->sender->agent($this->company->id, $this->agent->id, $pending, (string) Str::uuid(), 'both answered');
    $this->getJson($this->url)->assertJsonPath('inbox.total', 2)->assertJsonPath('pendingMessageCount', 0);
});

it('tracks unread customer messages per agent without changing last message time', function () {
    foreach ([$this->agent, $this->second] as $user) {
        $this->agents->grant($this->company->id, $user->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    }
    $id = $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'first');
    $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'second');
    $this->actingAs($this->agent, 'tenant_user');
    $time = $this->getJson($this->url)->assertJsonPath('inbox.data.0.unreadCount', 2)->assertJsonPath('unreadMessageCount', 2)->json('inbox.data.0.lastMessageAt');
    expect($time)->not->toBeNull();
    expect(DB::table('support_agent_reads')->count())->toBe(0);
    $read = $this->url.'/conversations/'.$id.'/read';
    $this->postJson($read, ['through' => 3])->assertUnprocessable();
    $this->postJson($read, ['through' => 2])->assertNoContent();
    $this->postJson($read, ['through' => 1])->assertNoContent();
    $this->getJson($this->url)->assertJsonPath('inbox.data.0.unreadCount', 0)->assertJsonPath('unreadMessageCount', 0)->assertJsonPath('inbox.data.0.lastMessageAt', $time);
    $this->actingAs($this->second, 'tenant_user')->getJson($this->url)->assertJsonPath('inbox.data.0.unreadCount', 2);
    $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'third');
    $this->actingAs($this->agent, 'tenant_user')->getJson($this->url)->assertJsonPath('inbox.data.0.unreadCount', 1);
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => false, 'revision' => 1]);
    $this->postJson($read, ['through' => 3])->assertForbidden();
});

it('exposes only the active agents own unread count for global reminders', function () {
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    $id = $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'hello');
    $this->actingAs($this->customer, 'tenant_user')->getJson('http://a.localhost/api/v1/unread')->assertJsonPath('agentSupport', 0);
    $this->actingAs($this->agent, 'tenant_user')->getJson('http://a.localhost/api/v1/unread')->assertJsonPath('agentSupport', 1);
    $this->postJson($this->url.'/conversations/'.$id.'/read', ['through' => 1])->assertNoContent();
    $this->getJson('http://a.localhost/api/v1/unread')->assertJsonPath('agentSupport', 0);
    $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'again');
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => false, 'revision' => 1]);
    $this->getJson('http://a.localhost/api/v1/unread')->assertJsonPath('agentSupport', 0);
});

it('exposes a read-only scoped customer profile and denies foreign own and revoked access', function () {
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    $id = $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'profile');
    $url = $this->url.'/conversations/'.$id.'/customer';
    $before = [DB::table('wallets')->count(), DB::table('ledger_entries')->count(), DB::table('promotion_members')->count()];
    $this->actingAs($this->agent, 'tenant_user')->getJson($url)->assertOk()
        ->assertJsonPath('accountId', $this->customer->account_id)->assertJsonPath('email', $this->customer->email)
        ->assertJsonPath('rank', 0)->assertJsonPath('partner', false)
        ->assertJsonPath('deposits', [])->assertJsonPath('withdrawals', [])
        ->assertJsonMissingPath('password_hash')->assertJsonMissingPath('phone');
    expect([DB::table('wallets')->count(), DB::table('ledger_entries')->count(), DB::table('promotion_members')->count()])->toBe($before);
    $foreign = User::where('tenant_id', $this->other->id)->firstOrFail();
    $foreignChat = $this->sender->user($this->other->id, $foreign->id, (string) Str::uuid(), 'foreign');
    $own = $this->sender->user($this->company->id, $this->agent->id, (string) Str::uuid(), 'own');
    foreach ([$foreignChat, $own] as $blocked) {
        $this->getJson($this->url.'/conversations/'.$blocked.'/customer')->assertNotFound();
    }
    $this->actingAs($this->customer, 'tenant_user')->getJson($url)->assertForbidden();
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => false, 'revision' => 1]);
    $this->actingAs($this->agent, 'tenant_user')->getJson($url)->assertForbidden();
});

it('shares revision checked customer remarks between Platform and company agents without changing names', function () {
    foreach ([$this->agent, $this->second] as $user) $this->agents->grant($this->company->id, $user->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    $id = $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'hello');
    $url = $this->url.'/conversations/'.$id;
    $name = $this->customer->profile?->display_name ?: $this->customer->account_id;
    $this->actingAs($this->agent, 'tenant_user')->postJson($url.'/remark', ['remark' => '  重点客户  ', 'revision' => 0])->assertNoContent();
    $this->actingAs($this->second, 'tenant_user')->getJson($url)->assertJsonPath('customerName', '重点客户');
    $this->getJson($this->url.'?search='.urlencode('重点客户'))->assertJsonPath('inbox.total', 1)->assertJsonPath('inbox.data.0.name', '重点客户');
    $this->getJson($url.'/customer')->assertJsonPath('name', $name)->assertJsonPath('remark', '重点客户')->assertJsonPath('remarkRevision', 1);
    $this->postJson($url.'/remark', ['remark' => 'stale', 'revision' => 0])->assertConflict();
    $this->postJson($url.'/remark', ['remark' => str_repeat('x', 61), 'revision' => 1])->assertUnprocessable();
    $platform = 'http://admin.localhost/platform/tenants/'.$this->company->id.'/users/'.$this->customer->id.'/support-remark';
    $this->actingAs($this->owner, 'platform_admin')->postJson($platform, ['remark' => '后台备注', 'revision' => 1])->assertNoContent();
    $this->postJson(str_replace($this->company->id, $this->other->id, $platform), ['remark' => 'foreign', 'revision' => 2])->assertNotFound();
    $this->actingAs($this->agent, 'tenant_user')->getJson($url.'/customer')->assertJsonPath('name', $name)->assertJsonPath('remark', '后台备注');
    $this->postJson($url.'/remark', ['remark' => '', 'revision' => 2])->assertNoContent();
    $this->getJson($url)->assertJsonPath('customerName', $name);
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => false, 'revision' => 1]);
    $this->postJson($url.'/remark', ['remark' => 'denied', 'revision' => 3])->assertForbidden();
    $this->actingAs($this->customer, 'tenant_user')->postJson($url.'/remark', ['remark' => 'denied', 'revision' => 3])->assertForbidden();
});

it('previews the latest message including edits images and deletion without exposing old text', function () {
    $this->agents->grant($this->company->id, $this->agent->id, $this->owner->id, ['enabled' => true, 'revision' => 0]);
    $id = $this->sender->user($this->company->id, $this->customer->id, (string) Str::uuid(), "customer\nquestion");
    $this->actingAs($this->agent, 'tenant_user')->getJson($this->url)->assertJsonPath('inbox.data.0.lastMessage.text', 'customer question');
    $this->sender->agent($this->company->id, $this->agent->id, $id, (string) Str::uuid(), '', kycTestImage());
    $message = SupportMessage::where('conversation_id', $id)->orderByDesc('sequence')->firstOrFail();
    $this->getJson($this->url)->assertJsonPath('inbox.data.0.lastMessage.image', true)->assertJsonPath('inbox.data.0.lastMessage.text', '');
    $change = $this->url.'/conversations/'.$id.'/messages/'.$message->id.'/change';
    $this->postJson($change, ['request_id' => (string) Str::uuid(), 'revision' => 0, 'operation' => 'EDIT', 'text' => 'updated reply'])->assertNoContent();
    $this->getJson($this->url)->assertJsonPath('inbox.data.0.lastMessage.text', 'updated reply');
    $this->postJson($change, ['request_id' => (string) Str::uuid(), 'revision' => 1, 'operation' => 'DELETE'])->assertNoContent();
    $this->getJson($this->url)->assertJsonPath('inbox.data.0.lastMessage', ['text' => '', 'deleted' => true, 'image' => false]);
});
