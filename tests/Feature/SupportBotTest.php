<?php

use App\Application\Support\SendSupportMessageAction;
use App\Application\Support\SupportBot;
use App\Application\Support\SupportChatQuery;
use App\Application\Support\SupportFaqs;
use App\Application\Support\SupportUnread;
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
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    Http::preventStrayRequests();
    Storage::fake('private');
    config(['inertia.ssr.enabled' => false]);
    $this->company = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $this->customer = User::where('tenant_id', $this->company->id)->firstOrFail();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->bot = app(SupportBot::class);
    $this->faqs = app(SupportFaqs::class);
    $this->send = app(SendSupportMessageAction::class);
    $this->input = ['question' => '如何开卡？', 'variants' => ['开卡流程'], 'keywords' => ['开卡'], 'answer' => '请先完成实名认证，再选择卡片申请。', 'enabled' => true, 'revision' => 0];
    $this->actingAs($this->owner, 'platform_admin');
});

it('keeps reads empty and settings off, and preserves old human conversations when enabling', function () {
    $query = app(SupportChatQuery::class);
    expect($query->user($this->company->id, $this->customer->id)['mode'])->toBe('HUMAN');
    $this->get('http://admin.localhost/platform/support/bot')->assertOk();
    expect(DB::table('support_bot_settings')->count())->toBe(0)->and(SupportConversation::count())->toBe(0);
    $id = $this->send->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'old');
    $this->bot->configure($this->company->id, $this->owner->id, true, 0);
    $this->send->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'still human');
    expect(SupportConversation::find($id)->mode)->toBe('HUMAN')->and(SupportMessage::count())->toBe(2);
    expect(fn () => $this->bot->configure($this->company->id, $this->owner->id, false, 0))->toThrow(HttpException::class);
});

it('matches normalized questions and variants, rejects ambiguity and ignores unrelated or image-only questions', function () {
    $id = $this->faqs->save($this->owner->id, null, null, $this->input);
    expect($this->faqs->match($this->company->id, ' 如何 开卡?! ')['answer']['id'])->toBe($id)
        ->and($this->faqs->match($this->company->id, '开卡流程')['answer']['id'])->toBe($id)
        ->and($this->faqs->match($this->company->id, '想咨询开卡的问题')['answer']['id'])->toBe($id)
        ->and($this->faqs->match($this->company->id, '天气如何')['answer'])->toBeNull()
        ->and($this->faqs->match($this->company->id, '')['answer'])->toBeNull();
    $this->faqs->save($this->owner->id, $this->company->id, null, $this->input);
    expect($this->faqs->match($this->company->id, '如何开卡')['answer'])->toBeNull();
    expect($this->faqs->match($this->other->id, '如何开卡')['answer']['id'])->toBe($id);
});

it('supports company overrides, local disabling and archiving without leaking across companies', function () {
    $global = $this->faqs->save($this->owner->id, null, null, $this->input);
    $override = [...$this->input, 'overrides_id' => $global, 'answer' => '本公司专属答案'];
    $id = $this->faqs->save($this->owner->id, $this->company->id, null, $override);
    expect($this->faqs->match($this->company->id, '如何开卡')['answer']['answer'])->toBe('本公司专属答案')
        ->and($this->faqs->match($this->other->id, '如何开卡')['answer']['id'])->toBe($global);
    $this->faqs->save($this->owner->id, $this->company->id, $id, [...$override, 'revision' => 1, 'enabled' => false]);
    expect($this->faqs->match($this->company->id, '如何开卡')['answer'])->toBeNull();
    $this->faqs->save($this->owner->id, $this->company->id, $id, [...$override, 'revision' => 2, 'archived' => true]);
    expect($this->faqs->match($this->company->id, '如何开卡')['answer']['id'])->toBe($global);
    $this->getJson('http://admin.localhost/platform/support/bot/faqs/'.$id.'?company='.$this->other->id)->assertNotFound();
    $this->postJson('http://admin.localhost/platform/support/bot/faqs', [...$override, 'company' => $this->company->id, 'id' => $id, 'revision' => 1])->assertConflict();
});

it('atomically snapshots one encrypted bot reply per user message and counts bot unread without staff identities', function () {
    $faq = $this->faqs->save($this->owner->id, null, null, $this->input);
    $this->bot->configure($this->company->id, $this->owner->id, true, 0);
    $request = (string) Str::uuid();
    $ledger = DB::table('ledger_entries')->count();
    $this->send->user($this->company->id, $this->customer->id, $request, '如何开卡');
    $this->faqs->save($this->owner->id, null, $faq, [...$this->input, 'revision' => 1, 'answer' => '更新后的答案']);
    $this->send->user($this->company->id, $this->customer->id, $request, '如何开卡');
    $reply = SupportMessage::where('is_bot', true)->sole();
    expect(SupportMessage::count())->toBe(2)->and($reply->support_message)->toBe($this->input['answer'])
        ->and($reply->faq_revision)->toBe(1)->and($reply->sender_admin_id)->toBeNull()->and($reply->sender_user_id)->toBeNull()
        ->and(DB::table('support_messages')->where('id', $reply->id)->value('support_message'))->not->toContain($this->input['answer'])
        ->and(app(SupportUnread::class)->count($this->company->id, $this->customer->id))->toBe(1)
        ->and(DB::table('ledger_entries')->count())->toBe($ledger);
    $chat = app(SupportChatQuery::class)->user($this->company->id, $this->customer->id);
    expect($chat['mode'])->toBe('BOT')->and($chat['messages'][1]['senderKind'])->toBe('BOT')->and($chat['messages'][1])->not->toHaveKey('sender_admin_id');
    app(SupportUnread::class)->read($this->company->id, $this->customer->id, 2);
    expect(app(SupportUnread::class)->count($this->company->id, $this->customer->id))->toBe(0);
});

it('hands off an empty chat once, stops bot replies, takes over and returns to bot with stale revision protection', function () {
    $this->bot->configure($this->company->id, $this->owner->id, true, 0);
    $request = (string) Str::uuid();
    $this->actingAs($this->customer, 'tenant_user')->post('http://a.localhost/support/handoff', ['request_id' => $request])->assertRedirect();
    $this->bot->handoff($this->company->id, $this->customer->id, $request);
    expect(SupportMessage::count())->toBe(1)->and(SupportConversation::sole()->mode)->toBe('WAITING');
    $this->send->user($this->company->id, $this->customer->id, (string) Str::uuid(), '继续补充');
    expect(SupportMessage::count())->toBe(2);
    $this->send->platform($this->company->id, $this->owner->id, $this->customer->id, (string) Str::uuid(), '人工回复');
    $conversation = SupportConversation::sole();
    expect($conversation->mode)->toBe('HUMAN');
    $url = 'http://admin.localhost/platform/tenants/'.$this->company->id.'/support/users/'.$this->customer->id.'/finish';
    $this->actingAs($this->owner, 'platform_admin')->postJson($url, ['request_id' => (string) Str::uuid(), 'revision' => $conversation->revision - 1])->assertConflict();
    $finish = ['request_id' => (string) Str::uuid(), 'revision' => $conversation->revision];
    $this->postJson($url, $finish)->assertNoContent();
    $this->postJson($url, $finish)->assertNoContent();
    $this->bot->handoff($this->company->id, $this->customer->id, $request);
    expect(SupportConversation::sole()->mode)->toBe('BOT')->and(SupportMessage::count())->toBe(4);
    $this->send->user($this->company->id, $this->customer->id, (string) Str::uuid(), '未知问题');
    expect(SupportMessage::count())->toBe(6)->and(SupportMessage::orderByDesc('sequence')->first()->support_message)->toContain('转人工');
});

it('disables only robot conversations and exposes waiting requests to the human queue', function () {
    $this->bot->configure($this->company->id, $this->owner->id, true, 0);
    $this->send->user($this->company->id, $this->customer->id, (string) Str::uuid(), '问题');
    $this->get('http://admin.localhost/platform/support?status=awaiting')->assertInertia(fn ($p) => $p->where('inbox.total', 0));
    $this->bot->handoff($this->company->id, $this->customer->id, (string) Str::uuid());
    $this->get('http://admin.localhost/platform/support?status=awaiting')->assertInertia(fn ($p) => $p->where('inbox.total', 1));
    $this->bot->configure($this->company->id, $this->owner->id, false, 1);
    expect(SupportConversation::sole()->mode)->toBe('WAITING');
    $this->bot->finish($this->company->id, $this->customer->id, $this->owner->id, (string) Str::uuid(), SupportConversation::sole()->revision, true);
    expect(SupportConversation::sole()->mode)->toBe('HUMAN');
});

it('enforces bot management permissions and validates preview and scope without creating messages', function () {
    $this->faqs->save($this->owner->id, null, null, $this->input);
    $this->postJson('http://admin.localhost/platform/support/bot/preview', ['company' => $this->company->id, 'question' => '如何开卡'])->assertOk()->assertJsonPath('answer.answer', $this->input['answer']);
    $this->getJson('http://admin.localhost/platform/support/bot?company=bad')->assertUnprocessable();
    expect(SupportMessage::count())->toBe(0);
    DB::table('role_permissions')->where('role_id', Role::where('name', 'PLATFORM_OWNER')->value('id'))->where('permission_id', DB::table('permissions')->where('name', 'support.bot.manage')->value('id'))->delete();
    $this->get('http://admin.localhost/platform/support/bot')->assertForbidden();
    $this->postJson('http://admin.localhost/platform/support/bot/faqs', $this->input)->assertForbidden();
    $this->postJson('http://admin.localhost/platform/support/bot/preview', ['company' => $this->company->id, 'question' => '如何开卡'])->assertForbidden();
});

it('rolls back the customer message if automatic reply persistence fails and handles image-only fallback', function () {
    $this->bot->configure($this->company->id, $this->owner->id, true, 0);
    $dispatcher = SupportMessage::getEventDispatcher();
    SupportMessage::setEventDispatcher(clone $dispatcher);
    $request = (string) Str::uuid();
    try {
        SupportMessage::creating(function ($message) {
            if ($message->is_bot) {
                throw new RuntimeException('synthetic reply failure');
            }
        });
        expect(fn () => $this->send->user($this->company->id, $this->customer->id, $request, '问题'))->toThrow(RuntimeException::class);
    } finally {
        SupportMessage::setEventDispatcher($dispatcher);
    }
    expect(SupportMessage::count())->toBe(0)->and(SupportConversation::count())->toBe(0);
    $this->send->user($this->company->id, $this->customer->id, $request, '', kycTestImage());
    expect(SupportMessage::count())->toBe(2)->and(SupportMessage::where('is_bot', true)->sole()->support_message)->toContain('转人工');
});

it('serves authenticated H5 and native handoff endpoints with server-owned company and user identity', function () {
    $this->bot->configure($this->company->id, $this->owner->id, true, 0);
    $token = $this->postJson('http://a.localhost/api/mobile/v1/login', ['identifier' => 'user@a.localhost', 'password' => 'local-password', 'device_name' => 'Offline bot test'])->assertCreated()->json('token');
    $this->withToken($token)->getJson('http://a.localhost/api/mobile/v1/support')->assertJsonPath('mode', 'BOT');
    $this->postJson('http://a.localhost/api/mobile/v1/support/handoff', ['request_id' => (string) Str::uuid(), 'tenant_id' => $this->other->id])->assertNoContent();
    $this->getJson('http://a.localhost/api/mobile/v1/support')->assertJsonPath('mode', 'WAITING');
    $this->getJson('http://b.localhost/api/mobile/v1/support')->assertUnauthorized();
    $this->flushHeaders();
    $this->actingAs($this->customer, 'tenant_user')->postJson('http://a.localhost/api/v1/support/handoff', ['request_id' => (string) Str::uuid()])->assertNoContent();
    expect(SupportConversation::sole()->tenant_id)->toBe($this->company->id)->and(SupportMessage::count())->toBe(1);
});

it('serializes simultaneous bot sends, duplicate retries and handoff in the isolated database', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required');
    }
    if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'card_ui_test' || DB::transactionLevel() !== 1) {
        throw new RuntimeException('Requires isolated test database');
    }
    $this->bot->configure($this->company->id, $this->owner->id, true, 0);
    $tenant = $this->company->id;
    $user = $this->customer->id;
    $request = (string) Str::uuid();
    DB::commit();
    RefreshDatabaseState::$migrated = false;
    DB::disconnect();
    $directory = sys_get_temp_dir().'/bot-race-'.Str::uuid();
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
                if ($i < 2) {
                    app(SendSupportMessageAction::class)->user($tenant, $user, $request, '并发问题');
                } else {
                    app(SupportBot::class)->handoff($tenant, $user, (string) Str::uuid());
                }
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
    expect($results)->toBe(['ok', 'ok', 'ok'])->and(SupportConversation::sole()->mode)->toBe('WAITING')
        ->and(SupportMessage::whereNotNull('sender_user_id')->count())->toBe(1)
        ->and(SupportMessage::whereNotNull('reply_to_id')->count())->toBeLessThanOrEqual(1)
        ->and(SupportConversation::sole()->last_sequence)->toBe(SupportMessage::count());
    $count = SupportMessage::count();
    app(SendSupportMessageAction::class)->user($tenant, $user, (string) Str::uuid(), '接管后问题');
    expect(SupportMessage::count())->toBe($count + 1);
});
