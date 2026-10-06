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
    $this->actingAs($this->agent, 'tenant_user')->getJson($this->url)->assertJsonPath('inbox.total', 1);
    $this->getJson($this->url.'/conversations/'.$own)->assertNotFound();
    $this->getJson($this->url.'/conversations/'.$foreign)->assertNotFound();
    $this->getJson($this->url.'/images/'.$foreignImage)->assertNotFound();
    $this->postJson($this->url.'/conversations/'.$own.'/messages', ['request_id' => (string) Str::uuid(), 'support_message' => 'forged'])->assertNotFound();
    $this->getJson($this->url.'/conversations/'.$id)->assertOk();
    $this->actingAs($this->second, 'tenant_user')->getJson($this->url)->assertJsonPath('inbox.total', 2);
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
