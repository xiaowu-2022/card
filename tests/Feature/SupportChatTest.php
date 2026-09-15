<?php

use App\Application\Support\SendSupportMessageAction;
use App\Application\Support\SupportChatQuery;
use App\Application\Support\SupportImageStorage;
use App\Domain\Admin\Models\AdminMembership;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Support\Models\SupportConversation;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use App\Support\Logging\SensitiveDataRedactor;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false]);
    $this->company = Tenant::query()->where('slug', 'tenant-a')->firstOrFail();
    $this->customer = User::query()->where('tenant_id', $this->company->id)->firstOrFail();
    $this->agent = AdminUser::query()->where('email', 'owner@a.localhost')->firstOrFail();
    $this->action = app(SendSupportMessageAction::class);
    $this->query = app(SupportChatQuery::class);
});

it('opens an empty support page without creating records', function (): void {
    $this->actingAs($this->customer, 'tenant_user')->get('http://a.localhost/support')->assertOk()
        ->assertInertia(fn ($page) => $page->component('user/Support')->where('chat.messages', []));
    expect(SupportConversation::query()->count())->toBe(0);
});

it('supports real two-way messages with encrypted text and no money changes', function (): void {
    $ledgerCount = DB::table('ledger_entries')->count();
    $this->actingAs($this->customer, 'tenant_user')->post('http://a.localhost/support/messages', [
        'request_id' => (string) Str::uuid(), 'support_message' => 'Hello support',
        'tenant_id' => Tenant::query()->where('slug', 'tenant-b')->value('id'), 'sender_admin_id' => $this->agent->id,
    ])->assertRedirect('/support');
    $conversation = SupportConversation::query()->firstOrFail();
    expect($conversation->tenant_id)->toBe($this->company->id);
    $this->actingAs($this->agent, 'tenant_admin')->post("http://a.localhost/admin/support/{$conversation->id}/messages", [
        'request_id' => (string) Str::uuid(), 'support_message' => 'How can we help?',
    ])->assertRedirect('/admin/support/'.$conversation->id);
    $chat = $this->query->user($this->company->id, $this->customer->id);
    expect(array_column($chat['messages'], 'text'))->toBe(['Hello support', 'How can we help?'])
        ->and(array_column($chat['messages'], 'fromSupport'))->toBe([false, true])
        ->and(DB::table('support_messages')->first()->support_message)->not->toContain('Hello support')
        ->and(SupportMessage::query()->first()->toArray())->not->toHaveKey('support_message')
        ->and($chat['messages'][1])->not->toHaveKey('sender_admin_id')
        ->and(DB::table('ledger_entries')->count())->toBe($ledgerCount);
    $this->actingAs($this->agent, 'tenant_admin')->get('http://a.localhost/admin/support')->assertOk()
        ->assertInertia(fn ($page) => $page->where('inbox.items.0.awaitingReply', false));
    $this->get('http://a.localhost/admin/support/'.$conversation->id)->assertOk();
});

it('sends image-only messages privately and allows both participants to view them', function (): void {
    $image = kycTestImage('chat.png');
    $bytes = $image->get();
    $this->actingAs($this->customer, 'tenant_user')->post('http://a.localhost/support/messages', [
        'request_id' => (string) Str::uuid(), 'support_image' => $image,
    ])->assertRedirect('/support');
    $message = SupportMessage::query()->firstOrFail();
    expect(Storage::disk('private')->get($message->image_object_key))->not->toBe($bytes)
        ->and($message->toArray())->not->toHaveKey('image_object_key')
        ->and($this->query->user($this->company->id, $this->customer->id)['messages'][0]['imageUrl'])->toBe('/support/images/'.$message->id);
    $this->get('http://a.localhost/support/images/'.$message->id)->assertOk()->assertHeader('Content-Type', 'image/png')->assertContent($bytes);
    $this->actingAs($this->agent, 'tenant_admin')->get('http://a.localhost/admin/support/images/'.$message->id)
        ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertContent($bytes);
    $this->post('http://a.localhost/admin/support/'.$message->conversation_id.'/messages', [
        'request_id' => (string) Str::uuid(), 'support_image' => kycTestImage('reply.png'), 'support_message' => 'See screenshot',
    ])->assertRedirect();
    expect(SupportMessage::query()->whereNotNull('sender_admin_id')->count())->toBe(1);
});

it('rejects other customers, other companies and platform-only admins from private conversations and images', function (): void {
    $conversationId = $this->action->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'private', kycTestImage());
    $message = SupportMessage::query()->firstOrFail();
    $other = $this->customer->replicate(['account_id']);
    $other->forceFill(['email' => 'another@example.test'])->save();
    $this->actingAs($other, 'tenant_user')->get('http://a.localhost/support/images/'.$message->id)->assertNotFound();
    expect($this->query->user($this->company->id, $other->id)['messages'])->toBe([]);
    $adminB = AdminUser::query()->where('email', 'owner@b.localhost')->firstOrFail();
    $this->actingAs($adminB, 'tenant_admin')->get('http://b.localhost/admin/support/'.$conversationId)->assertNotFound();
    $this->get('http://b.localhost/admin/support/images/'.$message->id)->assertNotFound();
    $this->post('http://b.localhost/admin/support/'.$conversationId.'/messages', ['request_id' => (string) Str::uuid(), 'support_message' => 'wrong company'])->assertNotFound();
    $this->get('http://a.localhost/admin/support/'.$conversationId)->assertForbidden();
    $platform = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($platform, 'tenant_admin')->get('http://a.localhost/admin/support')->assertForbidden();
});

it('requires active admin membership and support permission on every read and reply', function (): void {
    $conversationId = $this->action->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'help');
    $membership = AdminMembership::query()->where('scope_id', $this->company->id)->where('admin_user_id', $this->agent->id)->firstOrFail();
    $membership->update(['role_id' => Role::query()->where('name', 'FINANCE_VIEWER')->value('id')]);
    $this->actingAs($this->agent, 'tenant_admin')->get('http://a.localhost/admin/support/'.$conversationId)->assertForbidden();
    $membership->update(['role_id' => Role::query()->where('name', 'SUPPORT')->value('id')]);
    $this->get('http://a.localhost/admin/support/'.$conversationId)->assertOk();
    $membership->update(['status' => 'SUSPENDED']);
    $this->get('http://a.localhost/admin/support/'.$conversationId)->assertForbidden();
    $this->post('http://a.localhost/admin/support/'.$conversationId.'/messages', ['request_id' => (string) Str::uuid(), 'support_message' => 'reply'])->assertForbidden();
});

it('keeps retries idempotent including image bytes and rejects changed intent', function (): void {
    $requestId = (string) Str::uuid();
    $first = $this->action->user($this->company->id, $this->customer->id, $requestId, 'image', kycTestImage());
    expect($this->action->user($this->company->id, $this->customer->id, $requestId, 'image', kycTestImage()))->toBe($first)
        ->and(SupportMessage::query()->count())->toBe(1)
        ->and(count(Storage::disk('private')->allFiles('support')))->toBe(1);
    expect(fn () => $this->action->user($this->company->id, $this->customer->id, $requestId, 'changed', kycTestImage()))->toThrow(DomainException::class);
    expect(fn () => $this->action->user($this->company->id, $this->customer->id, $requestId, 'image'))->toThrow(DomainException::class);
});

it('removes only the staged image when a message write definitively rolls back', function (): void {
    $this->action->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'keep', kycTestImage());
    $existing = Storage::disk('private')->allFiles('support');
    $dispatcher = SupportMessage::getEventDispatcher();
    SupportMessage::setEventDispatcher(clone $dispatcher);
    try {
        SupportMessage::created(function (): void {
            throw new RuntimeException('Isolated message write failure');
        });
        expect(fn () => $this->action->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'failed', kycTestImage()))
            ->toThrow(RuntimeException::class, 'Isolated message write failure');
    } finally {
        SupportMessage::setEventDispatcher($dispatcher);
    }
    expect(SupportMessage::query()->count())->toBe(1)
        ->and(Storage::disk('private')->allFiles('support'))->toBe($existing)
        ->and(SupportConversation::query()->sole()->last_sequence)->toBe(1);
});

it('refuses staged cleanup outside the exact company image directory', function (): void {
    expect(fn () => app(SupportImageStorage::class)->discardStaged($this->company->id, 'support/other/file.enc'))
        ->toThrow(LogicException::class);
});

it('retains an image if failure is reported after the transaction body completed', function (): void {
    $requestId = (string) Str::uuid();
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $isolated = clone $dispatcher;
    $connection->setEventDispatcher($isolated);
    $isolated->listen(TransactionCommitted::class, function (): void {
        throw new RuntimeException('Isolated post-commit uncertainty');
    });
    try {
        expect(fn () => $this->action->user($this->company->id, $this->customer->id, $requestId, 'keep on uncertainty', kycTestImage()))
            ->toThrow(RuntimeException::class, 'Isolated post-commit uncertainty');
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }
    $message = SupportMessage::query()->sole();
    Storage::disk('private')->assertExists($message->image_object_key);
    $this->action->user($this->company->id, $this->customer->id, $requestId, 'keep on uncertainty', kycTestImage());
    expect(SupportMessage::query()->count())->toBe(1)->and(Storage::disk('private')->allFiles('support'))->toHaveCount(1);
});

it('bounds history with stable sequence cursors', function (): void {
    for ($i = 1; $i <= 52; $i++) {
        $this->action->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'Message '.$i);
    }
    $latest = $this->query->user($this->company->id, $this->customer->id);
    expect($latest['messages'])->toHaveCount(50)
        ->and($latest['messages'][0]['sequence'])->toBe(3)
        ->and($latest['olderCursor'])->toBe(3);
    $older = $this->query->user($this->company->id, $this->customer->id, $latest['olderCursor']);
    expect(array_column($older['messages'], 'sequence'))->toBe([1, 2])->and($older['olderCursor'])->toBeNull();
});

it('rejects missing messages, invalid images, oversize files and limits input', function (): void {
    $this->actingAs($this->customer, 'tenant_user');
    $this->post('http://a.localhost/support/messages', ['request_id' => (string) Str::uuid()])->assertSessionHasErrors('support_message');
    $this->post('http://a.localhost/support/messages', ['request_id' => (string) Str::uuid(), 'support_message' => str_repeat('x', 2001)])->assertSessionHasErrors('support_message');
    foreach ([
        UploadedFile::fake()->createWithContent('bad.png', '<svg onload="alert(1)"></svg>'),
        UploadedFile::fake()->create('large.jpg', 5121, 'image/jpeg'),
    ] as $image) {
        $this->post('http://a.localhost/support/messages', ['request_id' => (string) Str::uuid(), 'support_image' => $image])->assertSessionHasErrors();
    }
    expect(SupportMessage::query()->count())->toBe(0);
});

it('preserves the restricted account boundary and does not log chat contents', function (): void {
    $this->customer->update(['status' => 'SUSPENDED']);
    $this->actingAs($this->customer, 'tenant_user')->get('http://a.localhost/support')->assertRedirect('/account/restricted');
    $this->post('http://a.localhost/support/messages', ['request_id' => (string) Str::uuid(), 'support_message' => 'private'])->assertRedirect('/account/restricted');
    expect(app(SensitiveDataRedactor::class)->redact(['support_message' => 'private', 'support_image' => 'bytes', 'image_object_key' => 'secret']))->toBe([
        'support_message' => '[REDACTED]', 'support_image' => '[REDACTED]', 'image_object_key' => '[REDACTED]',
    ]);
});

it('rejects forged message ownership at the database boundary', function (): void {
    $conversationId = $this->action->user($this->company->id, $this->customer->id, (string) Str::uuid(), 'help');
    $other = $this->customer->replicate(['account_id']);
    $other->forceFill(['email' => 'other-chat@example.test'])->save();
    $original = SupportMessage::query()->firstOrFail()->getAttributes();
    expect(fn () => DB::transaction(fn () => DB::table('support_messages')->insert(array_merge($original, [
        'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'sequence' => 2, 'sender_user_id' => $other->id,
    ]))))->toThrow(QueryException::class);
    expect(SupportMessage::query()->where('conversation_id', $conversationId)->count())->toBe(1);
});

it('does not expose private images to guests or suspended administrators', function (): void {
    $this->action->user($this->company->id, $this->customer->id, (string) Str::uuid(), '', kycTestImage());
    $message = SupportMessage::query()->firstOrFail();
    $this->get('http://a.localhost/support/images/'.$message->id)->assertRedirect('/login');
    $this->get('http://a.localhost/admin/support/images/'.$message->id)->assertRedirect('/admin/login');
    $this->agent->update(['status' => 'SUSPENDED']);
    $this->actingAs($this->agent, 'tenant_admin')->get('http://a.localhost/admin/support/images/'.$message->id)->assertForbidden();
});
