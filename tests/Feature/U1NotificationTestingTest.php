<?php

use App\Application\CardProviderDirectory\CardProviderReferenceQuery;
use App\Application\CardProviderDirectory\DeleteCardProviderReferenceAction;
use App\Application\CardProviderDirectory\SaveCardProviderReferenceAction;
use App\Application\CardProviderDirectory\U1Accounts;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Providers\Card\U1NotificationVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function u1SignedNotification(string $type, string $key = 'offline-u1-key', array $extra = []): array
{
    $data = array_merge(['type' => $type, 'timestamp' => (string) now()->getTimestampMs(),
        'apiId' => 'RANDOM-NOT-A-BUSINESS-ORDER', 'cardNo' => 'RANDOM-CARD-IDENTIFIER', 'otp' => '876543'], $extra);
    $data['sign'] = md5(implode('', array_map(fn ($field) => $data[$field], U1NotificationVerifier::FIELDS[$type])).$key);

    return $data;
}

beforeEach(function (): void {
    $this->seed();
    Queue::fake();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false, 'app.url' => 'https://test.example.com']);
    Http::preventStrayRequests();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($this->owner, 'platform_admin');
    $this->url = 'http://admin.localhost/platform/card-providers';
    $this->input = ['provider_type' => 'U1', 'name' => 'U1 offline test', 'customer_no' => 'CUSTOMER-TEST',
        'api_key' => 'offline-u1-key', 'base_url' => U1Accounts::BASE_URL, 'request_id' => (string) Str::uuid()];
    $this->service = app(U1Accounts::class);
    $this->reference = $this->service->save(null, $this->input, $this->owner);
    $this->connection = DB::table('u1_connections')->where('reference_id', $this->reference->id)->first();
    $this->root = $this->url.'/'.$this->reference->id.'/u1';
    $this->callback = 'http://admin.localhost/webhooks/u1/'.$this->connection->id;
    $this->money = collect(['ledger_accounts', 'ledger_entries', 'ledger_postings', 'wallets', 'user_cards', 'card_issue_orders', 'card_management_orders'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
});

afterEach(function (): void {
    $current = collect(array_keys($this->money))->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    expect($current)->toBe($this->money);
});

it('saves a connection atomically with encrypted credentials and safe public configuration', function (): void {
    expect($this->connection->api_key_encrypted)->not->toContain('offline-u1-key')
        ->and(Crypt::decryptString($this->connection->api_key_encrypted))->toBe('offline-u1-key');
    $public = $this->service->publicConfiguration($this->reference->id);
    expect($public['callbackUrl'])->toBe('https://test.example.com/webhooks/u1/'.$this->connection->id)
        ->and($public['types'])->toHaveCount(9)->and(json_encode($public))->not->toContain('offline-u1-key');
    $this->get($this->url)->assertOk()->assertInertia(fn ($page) => $page
        ->where('providers.data.0.u1.customerNo', 'CUSTOMER-TEST')
        ->where('providers.data.0.u1.provider', 'U1')
        ->where('providers.data.0.photonpay', null)->where('providers.data.0.referenceBalance', null));
    expect(DB::table('audit_logs')->where('action', 'U1_NOTIFICATION_CONNECTION_SAVED')->get()->toJson())->not->toContain('offline-u1-key');
    expect(app(CardProviderReferenceQuery::class)->balanceRows())->toHaveCount(1)
        ->and(app(CardProviderReferenceQuery::class)->balanceTotal())->toBeNull();
    Http::assertNothingSent();
});

it('retains a blank secret, enforces versions and locks the customer identity', function (): void {
    $update = array_diff_key($this->input, ['request_id' => true]) + ['version' => 1];
    $update['api_key'] = '';
    $this->putJson($this->url.'/'.$this->reference->id, $update)->assertRedirect();
    expect(Crypt::decryptString(DB::table('u1_connections')->value('api_key_encrypted')))->toBe('offline-u1-key');
    $this->putJson($this->url.'/'.$this->reference->id, $update)->assertUnprocessable()->assertJsonValidationErrors('version');
    $this->putJson($this->url.'/'.$this->reference->id, [...$update, 'version' => 2, 'customer_no' => 'OTHER'])->assertUnprocessable()->assertJsonValidationErrors('customer_no');
    $this->putJson($this->url.'/'.$this->reference->id, [...$update, 'version' => 2, 'api_key' => 'replacement-offline-key'])->assertRedirect();
    expect(Crypt::decryptString(DB::table('u1_connections')->value('api_key_encrypted')))->toBe('replacement-offline-key');
    Http::assertNothingSent();
});

it('replays creation without replacing credentials and refuses changed payloads and tombstones', function (): void {
    $this->postJson($this->url, $this->input)->assertRedirect();
    expect(DB::table('u1_connections')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'U1_NOTIFICATION_CONNECTION_SAVED')->count())->toBe(1);
    $this->postJson($this->url, [...$this->input, 'api_key' => 'other-secret'])->assertUnprocessable();
    app(DeleteCardProviderReferenceAction::class)->execute($this->reference->id, ['version' => 1, 'confirmed' => true, 'request_id' => (string) Str::uuid()], $this->owner);
    $this->postJson($this->url, $this->input)->assertNotFound();
    $this->postJson($this->callback, u1SignedNotification('transaction'))->assertNotFound();
    Http::assertNothingSent();
});

it('rejects arbitrary origins and never flashes the supplied secret on validation failure', function (): void {
    $this->from($this->url)->post($this->url, [...$this->input, 'request_id' => (string) Str::uuid(), 'base_url' => 'https://evil.example.com'])
        ->assertSessionHasErrors('base_url')->assertSessionMissing('_old_input.api_key');
    expect(DB::table('u1_connections')->count())->toBe(1);
    Http::assertNothingSent();
});

it('rejects PhotonPay and manual conversion of U1 and U1 conversion of PhotonPay', function (): void {
    $this->putJson($this->url.'/'.$this->reference->id, ['name' => 'manual', 'version' => 1, 'reference_balance' => '0'])->assertUnprocessable();
    $this->putJson($this->url.'/'.$this->reference->id, ['name' => 'photon', 'version' => 1, 'environment' => 'sandbox', 'enabled' => true,
        'app_id' => 'app', 'app_secret' => 'secret', 'account_id' => 'account', 'member_id' => 'member'])->assertUnprocessable();
    [$photon] = photonAccountFixture($this->owner);
    $this->putJson($this->url.'/'.$photon->id, array_diff_key($this->input, ['request_id' => true]) + ['version' => $photon->version])->assertUnprocessable();
    Http::assertNothingSent();
});

it('receives each documented random notification and counts duplicate delivery without business mapping', function (string $type): void {
    $payload = u1SignedNotification($type);
    $this->postJson($this->callback, $payload)->assertOk()->assertSeeText('SUCCESS');
    $this->postJson($this->callback, $payload)->assertOk();
    $event = DB::table('u1_notifications')->sole();
    expect((bool) $event->verified)->toBeTrue()->and($event->delivery_count)->toBe(2)
        ->and($event->body_encrypted)->not->toContain('876543');
    $this->getJson($this->root.'/notifications')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.delivery_count', 2);
    $details = $this->getJson($this->root.'/notifications/'.$event->id)->assertOk()->json();
    expect($details['signedFields'])->toBe(U1NotificationVerifier::FIELDS[$type])
        ->and(json_encode($details))->not->toContain('876543');
    Http::assertNothingSent();
})->with(array_keys(U1NotificationVerifier::FIELDS));

it('preserves exact numeric lexemes, zero and signed amounts, unknown fields and field coverage', function (): void {
    $payload = u1SignedNotification('transaction', extra: ['cardno' => 'RANDOM-TRANSACTION-CARD', 'amount' => '-0.00000001',
        'billingamount' => '0', 'free' => '123456789012.12345678', 'freecurrency' => 'USD', 'unknownField' => ['future' => 'supported']]);
    $raw = str_replace('"-0.00000001"', '-0.00000001', json_encode($payload));
    $raw = str_replace('"123456789012.12345678"', '123456789012.12345678', $raw);
    $this->call('POST', '/webhooks/u1/'.$this->connection->id, [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)->assertOk();
    $event = DB::table('u1_notifications')->sole();
    expect(Crypt::decryptString($event->body_encrypted))->toBe($raw);
    $this->getJson($this->root.'/notifications/'.$event->id)->assertOk()
        ->assertJsonPath('fields.amount', '-0.00000001')->assertJsonPath('fields.free', '123456789012.12345678')
        ->assertJsonPath('fields.billingamount', '0')->assertJsonPath('fields.feeAmount', null)
        ->assertJsonPath('payload.unknownField.future', 'supported')->assertJsonPath('balanceIndependentlyVerified', false)
        ->assertJsonPath('signedFields', ['type', 'timestamp']);
    Http::assertNothingSent();
});

it('records bad signatures and malformed JSON but never acknowledges them as success', function (): void {
    $this->postJson($this->callback, u1SignedNotification('transaction', 'wrong-secret'))->assertStatus(400)->assertSeeText('INVALID_NOTIFICATION');
    $this->call('POST', '/webhooks/u1/'.$this->connection->id, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{broken')->assertStatus(400);
    expect(DB::table('u1_notifications')->where('verified', false)->count())->toBe(2);
    $event = DB::table('u1_notifications')->where('verification_reason', 'INVALID_JSON')->first();
    $this->getJson($this->root.'/notifications/'.$event->id)->assertOk()->assertJsonPath('payload', null);
    Http::assertNothingSent();
});

it('rejects unknown types, missing signed fields, malformed timestamps and oversized bodies', function (): void {
    $this->postJson($this->callback, ['type' => 'unknown'])->assertStatus(400);
    $this->postJson($this->callback, ['type' => 'openvcc.success', 'timestamp' => '1234567890123'])->assertStatus(400);
    $this->postJson($this->callback, u1SignedNotification('transaction', extra: ['timestamp' => 'invalid']))->assertStatus(400);
    $this->call('POST', '/webhooks/u1/'.$this->connection->id, [], [], [], [], str_repeat('x', 262145))->assertStatus(413);
    expect(DB::table('u1_notifications')->count())->toBe(3);
    Http::assertNothingSent();
});

it('recursively masks OTP, signature and credentials in structured and nested JSON fields', function (): void {
    $payload = u1SignedNotification('otp', extra: ['nested' => ['OTP' => '654321', 'apiKey' => 'never-display-key'],
        'data' => '{"otp":"123456","other":"retained"}']);
    $this->postJson($this->callback, $payload)->assertOk();
    $event = DB::table('u1_notifications')->sole();
    $json = $this->getJson($this->root.'/notifications/'.$event->id)->assertOk()->getContent();
    expect($json)->not->toContain('876543')->not->toContain('654321')->not->toContain('123456')->not->toContain('never-display-key')->toContain('retained');
});

it('keeps connections isolated for notifications, signing keys, pagination and request status', function (): void {
    $other = $this->service->save(null, [...$this->input, 'request_id' => (string) Str::uuid(), 'customer_no' => 'OTHER', 'api_key' => 'other-offline-key'], $this->owner);
    $otherConnection = DB::table('u1_connections')->where('reference_id', $other->id)->first();
    $this->postJson('http://admin.localhost/webhooks/u1/'.$otherConnection->id, u1SignedNotification('transaction'))->assertStatus(400);
    $this->postJson($this->callback, u1SignedNotification('transaction'))->assertOk();
    $event = DB::table('u1_notifications')->where('connection_id', $this->connection->id)->first();
    $this->getJson($this->url.'/'.$other->id.'/u1/notifications/'.$event->id)->assertNotFound();
    $this->getJson($this->url.'/'.$other->id.'/u1/notifications')->assertJsonPath('total', 1)->assertJsonPath('items.0.verified', false);
    Http::assertNothingSent();
});

it('returns exact numbered pagination without provider reads', function (): void {
    for ($i = 0; $i < 21; $i++) {
        $this->service->receive($this->connection->id, json_encode(u1SignedNotification('transaction', extra: ['unique' => (string) $i])));
    }
    $this->getJson($this->root.'/notifications?page=2')->assertOk()->assertJsonPath('total', 21)->assertJsonPath('pages', 2)->assertJsonPath('page', 2)->assertJsonCount(1, 'items');
    Http::assertNothingSent();
});

it('signs only notifytest requests, verifies responses and distinguishes acceptance from callback delivery', function (): void {
    Http::fake(function ($request) {
        expect($request->url())->toBe(\App\Infrastructure\Providers\Card\U1ApiClient::V2_BASE_URL.'/api/notifytest')
            ->and($request['customerNo'])->toBe('CUSTOMER-TEST')->and($request['editType'])->toBe('transaction')
            ->and($request['timestamp'])->toBeString()
            ->and($request['sign'])->toBe(md5($request['customerNo'].$request['timestamp'].'offline-u1-key'));

        return Http::response(['resultCode' => '0000', 'timestamp' => $request['timestamp'], 'apiId' => 'notify-test-request',
            'sign' => md5('0000'.$request['timestamp'].'offline-u1-key')]);
    });
    $input = ['request_id' => (string) Str::uuid(), 'version' => 1, 'notification_type' => 'transaction'];
    $this->postJson($this->root.'/tests', $input)->assertOk()->assertJsonPath('status', 'ACCEPTED')->assertJsonPath('notificationStatus', 'WAITING');
    $this->postJson($this->root.'/tests', $input)->assertOk();
    Http::assertSentCount(1);
    $this->postJson($this->callback, u1SignedNotification('transaction'))->assertOk();
    $this->getJson($this->root.'/notifications')->assertJsonPath('requests.0.notificationStatus', 'RECEIVED')
        ->assertJsonPath('requests.0.correlation', 'TYPE_AND_RECEIVE_TIME_ONLY');
    Http::assertSentCount(1);
});

it('handles callback before the test response and preserves unsigned transaction fields as unverified evidence', function (): void {
    Http::fake(function ($request) {
        $this->service->receive($this->connection->id, json_encode(u1SignedNotification('transaction', extra: ['amount' => '100.00', 'free' => '99999.00'])));

        return Http::response(['resultCode' => '0000', 'timestamp' => $request['timestamp'], 'sign' => md5('0000'.$request['timestamp'].'offline-u1-key')]);
    });
    $this->postJson($this->root.'/tests', ['request_id' => (string) Str::uuid(), 'version' => 1, 'notification_type' => 'transaction'])
        ->assertOk()->assertJsonPath('status', 'ACCEPTED')->assertJsonPath('notificationStatus', 'RECEIVED');
    Http::assertSentCount(1);
});

it('does not resend uncertain requests and shows absent callbacks after thirty seconds', function (): void {
    Http::fake(fn () => throw new ConnectionException('offline timeout'));
    $input = ['request_id' => (string) Str::uuid(), 'version' => 1, 'notification_type' => 'transaction'];
    $this->postJson($this->root.'/tests', $input)->assertOk()->assertJsonPath('status', 'UNKNOWN');
    $this->postJson($this->root.'/tests', $input)->assertOk()->assertJsonPath('status', 'UNKNOWN');
    expect(DB::table('u1_notification_tests')->count())->toBe(1);
    $this->travel(31)->seconds();
    $this->getJson($this->root.'/notifications')->assertJsonPath('requests.0.notificationStatus', 'NOT_RECEIVED');
});

it('reports invalid response signatures as unknown and signed failures as rejected', function (): void {
    Http::fakeSequence()->push(['resultCode' => '0000', 'timestamp' => '1234567890123', 'sign' => str_repeat('0', 32)])
        ->push(['resultCode' => '0002', 'timestamp' => '1234567890123', 'sign' => md5('0002'.'1234567890123'.'offline-u1-key')]);
    $input = ['request_id' => (string) Str::uuid(), 'version' => 1, 'notification_type' => 'transaction'];
    $this->postJson($this->root.'/tests', $input)->assertOk()->assertJsonPath('status', 'UNKNOWN');
    $this->postJson($this->root.'/tests', [...$input, 'request_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('status', 'REJECTED');
    Http::assertSentCount(2);
});

it('rejects stale test versions and replay with a different notification type before HTTP calls', function (): void {
    Http::fake(fn ($request) => Http::response(['resultCode' => '0000', 'timestamp' => $request['timestamp'], 'sign' => md5('0000'.$request['timestamp'].'offline-u1-key')]));
    $input = ['request_id' => (string) Str::uuid(), 'version' => 1, 'notification_type' => 'transaction'];
    $this->postJson($this->root.'/tests', [...$input, 'version' => 2])->assertUnprocessable();
    $this->postJson($this->root.'/tests', $input)->assertOk();
    $this->postJson($this->root.'/tests', [...$input, 'notification_type' => 'otp'])->assertStatus(409);
    Http::assertSentCount(1);
});

it('requires active Platform administrators for settings and notification reads', function (): void {
    $this->owner->forceFill(['status' => 'SUSPENDED'])->save();
    $this->getJson($this->root.'/notifications')->assertForbidden();
    $this->actingAs($this->owner, 'platform_admin')->postJson($this->root.'/tests', ['request_id' => (string) Str::uuid(), 'version' => 1, 'notification_type' => 'transaction'])->assertForbidden();
    $this->actingAs($this->owner, 'platform_admin')->putJson($this->url.'/'.$this->reference->id, array_diff_key($this->input, ['request_id' => true]) + ['version' => 1])->assertForbidden();
    Http::assertNothingSent();
});

it('never acknowledges persistence failure and does not leak callback content', function (): void {
    DB::statement('ALTER TABLE u1_notifications ADD CONSTRAINT offline_storage_failure CHECK (false)');
    $this->postJson($this->callback, u1SignedNotification('otp'))->assertStatus(503)->assertSeeText('RETRY')->assertDontSeeText('876543');
    DB::statement('ALTER TABLE u1_notifications DROP CONSTRAINT offline_storage_failure');
    expect(DB::table('u1_notifications')->count())->toBe(0);
    Http::assertNothingSent();
});

it('converts an unused reference with a new version and rolls back incomplete creations', function (): void {
    $reference = app(SaveCardProviderReferenceAction::class)->execute(null, ['name' => 'unused reference', 'reference_balance' => '0', 'request_id' => (string) Str::uuid()], $this->owner);
    $this->putJson($this->url.'/'.$reference->id, [...array_diff_key($this->input, ['request_id' => true]), 'customer_no' => 'CONVERTED-CUSTOMER', 'version' => 1])->assertRedirect();
    expect($reference->fresh()->version)->toBe(2)->and($reference->fresh()->name)->toBe('U1 offline test');
    $count = CardProviderReference::count();
    $auditCount = DB::table('audit_logs')->count();
    $this->postJson($this->url, [...$this->input, 'request_id' => (string) Str::uuid(), 'api_key' => ''])->assertUnprocessable();
    expect(CardProviderReference::count())->toBe($count)->and(DB::table('audit_logs')->count())->toBe($auditCount);
    Http::assertNothingSent();
});

it('retains all out of order success and failure notifications without advancing business state', function (): void {
    foreach (['rechargevcc.failed', 'rechargevcc.success', 'rechargevcc.failed'] as $index => $type) {
        $this->postJson($this->callback, u1SignedNotification($type, extra: ['timestamp' => (string) (now()->getTimestampMs() - $index * 10000)]))->assertOk();
    }
    $this->getJson($this->root.'/notifications')->assertOk()->assertJsonPath('total', 3);
    expect(DB::table('u1_notification_tests')->count())->toBe(0);
    Http::assertNothingSent();
});

it('checks U1 account and group catalogs explicitly and reserves the real BIN without double trigger claims', function (): void {
    Http::fake(function ($request) {
        $data = str_ends_with($request->url(), '/queryaccount')
            ? ['amount'=>[['currency'=>'USD','freeAmt'=>'12.12345678']], 'cardCount'=>'2']
            : ['group'=>[['currency'=>'USD','groupNo'=>'GROUP-ORIGINAL','bin'=>'412345','model'=>'SHARE','apiKey'=>'do-not-project'],
                ['currency'=>'USD','groupNo'=>'GROUP-NEW','bin'=>'412346','model'=>'SHARE']]];
        return Http::response(['resultCode'=>'0000','timestamp'=>$request['timestamp'],'sign'=>md5('0000'.$request['timestamp'].'offline-u1-key'),'data'=>$data]);
    });
    $this->service->check($this->reference->id, $this->owner);
    $public=$this->service->publicConfiguration($this->reference->id);
    expect($public['checkStatus'])->toBe('VERIFIED')->and(json_encode($public))->not->toContain('do-not-project');
    $this->service->save($this->reference->id,['name'=>'U1 standard','customer_no'=>'CUSTOMER-TEST','base_url'=>U1Accounts::BASE_URL,'api_key'=>'','version'=>1],$this->owner);
    $product=app(\App\Application\CardProduct\CreateCardProductAction::class)->execute(['name'=>'U1 BIN','card_provider_reference_id'=>$this->reference->id,
        'provider_product_ref'=>'GROUP-ORIGINAL','opening_fee'=>'0','minimum_initial_load'=>'20','minimum_reload'=>'20','status'=>'ACTIVE'], $this->owner);
    app(\App\Application\Card\CardProductProviderRouter::class)->assertNewBusiness($product);
    DB::table('card_products')->where('id',$product->id)->update(['source_bin'=>'412346','provider_product_ref'=>'GROUP-ORIGINAL']);
    expect(DB::table('card_bin_claims')->where('bin','412345')->exists())->toBeFalse()
        ->and(DB::table('card_bin_claims')->where('bin','412346')->value('card_product_id'))->toBe($product->id);
    Http::assertSentCount(2);
});

it('redacts a signed OTP even if duplicated inside unknown raw fields', function (): void {
    $body=json_encode(u1SignedNotification('otp', extra:['comment'=>'Verification code is 876543','unknown'=>['value'=>'876543']]));
    $event=$this->service->receive($this->connection->id,$body);
    expect(json_encode($this->service->notification($this->reference->id,$event['id'])))->not->toContain('876543');
});

it('recovers only a unique v2 request match and verifies its operation evidence', function (string $ids, string $eventId, bool $legacy): void {
    $id = (string) Str::uuid();
    $requestId = (string) Str::uuid();
    DB::table('u1_operations')->insert(['id' => $id, 'connection_id' => $this->connection->id,
        'request_id' => $requestId, 'intent_hash' => str_repeat('a', 64), 'kind' => 'LOAD',
        'card_id' => 'OFFLINE-CARD', 'currency' => 'USD', 'amount' => '20.00000000',
        'status' => 'UNKNOWN', 'request_ids_confirmed' => ! $legacy, 'requested_at' => now()]);
    Http::fake(function ($request) use ($id, $ids, $eventId) {
        expect($request->url())->toStartWith(\App\Infrastructure\Providers\Card\U1ApiClient::V2_BASE_URL.'/api/');
        if (str_ends_with($request->url(), '/queryrequestid')) {
            expect($request['requestId'])->toBe($id);
            $data = $ids;
        } else {
            expect($request->url())->toEndWith('/queryapiid')->and($request['apiId'])->toBe('OFFLINE-API');
            $data = json_encode(u1SignedNotification('rechargevcc.success', extra: ['apiId' => $eventId]));
        }

        return Http::response(['resultCode' => '0000', 'timestamp' => $request['timestamp'],
            'sign' => md5('0000'.$request['timestamp'].'offline-u1-key'), 'data' => $data]);
    });
    $provider = new \App\Infrastructure\Providers\Card\U1CardProvider($this->connection);
    $success = $ids === 'OFFLINE-API' && $eventId === 'OFFLINE-API' && ! $legacy;
    if ($legacy || $success) {
        $result = $provider->queryCardFunds('OFFLINE-CARD', $requestId, 'LOAD');
        expect($result->status->value)->toBe($success ? 'SUCCEEDED' : 'UNKNOWN');
    } else {
        expect(fn () => $provider->queryCardFunds('OFFLINE-CARD', $requestId, 'LOAD'))
            ->toThrow(\App\Domain\CardProvider\Exceptions\ProviderUnknownResultException::class);
    }
    expect(DB::table('u1_operations')->where('id', $id)->value('status'))->toBe($success ? 'SUCCEEDED' : 'UNKNOWN');
    Http::assertSentCount($legacy ? 0 : ($ids === 'OFFLINE-API' ? 2 : 1));
})->with([
    ['OFFLINE-API', 'OFFLINE-API', false], ['', 'OFFLINE-API', false],
    ['OFFLINE-API,OTHER', 'OFFLINE-API', false], ['OFFLINE-API', 'OTHER', false],
    ['OFFLINE-API', 'OFFLINE-API', true],
]);

it('persists a unique upstream operation ID before sending and never resends an ambiguous write', function (): void {
    DB::table('u1_connections')->where('id', $this->connection->id)->update(['enabled' => false, 'check_status' => 'VERIFIED', 'zero_fees_confirmed' => false]);
    $connection = DB::table('u1_connections')->where('id', $this->connection->id)->first();
    $requestId = (string) Str::uuid();
    $sent = [];
    Http::fake(function ($request) use ($connection, $requestId, &$sent) {
        $intent = DB::table('u1_operations')->where('connection_id', $connection->id)->where('request_id', $requestId)->firstOrFail();
        $sent[] = [$request['requestId'], $intent->id, $intent->request_ids_confirmed];
        throw new ConnectionException('offline timeout');
    });
    $provider = new \App\Infrastructure\Providers\Card\U1CardProvider($connection);
    $provider->loadCard('OFFLINE-CARD', '20.00', 'USD', $requestId);
    $provider->loadCard('OFFLINE-CARD', '20.00', 'USD', $requestId);
    expect($sent)->toHaveCount(1)->and($sent[0][0])->toBe($sent[0][1])->and($sent[0][2])->toBeTrue();
    expect(DB::table('u1_operations')->where('request_id', $requestId)->value('status'))->toBe('UNKNOWN');
});

it('logs every callback type at arrival including invalid signatures without exposing plaintext', function (string $type): void {
    $entries = [];
    $logger = Mockery::mock(\Psr\Log\LoggerInterface::class);
    $logger->shouldReceive('info')->andReturnUsing(function ($message, $context) use (&$entries) {
        $entries[] = [$message, $context];
    });
    $manager = Mockery::mock(\Illuminate\Log\LogManager::class, [app()])->makePartial();
    $manager->shouldReceive('channel')->with('u1_notifications')->andReturn($logger);
    \Illuminate\Support\Facades\Log::swap($manager);
    $body = u1SignedNotification($type, extra: ['sign' => 'unused']);
    $body['sign'] = str_repeat('0', 32);
    $this->postJson($this->callback, $body)->assertStatus(400);
    $arrival = collect($entries)->first(fn ($entry) => $entry[0] === 'U1 webhook arrived');
    $result = collect($entries)->first(fn ($entry) => $entry[0] === 'U1 webhook verified');
    expect($arrival)->not->toBeNull()->and($arrival[1]['notification_type'])->toBe($type)
        ->and(Crypt::decryptString($arrival[1]['body_encrypted']))->toBe(json_encode($body))
        ->and($result[1]['reason'])->toBe('INVALID_SIGNATURE')
        ->and($result[1]['receipt_id'])->toBe($arrival[1]['receipt_id']);
    unset($arrival[1]['body_encrypted']);
    expect(json_encode($arrival))->not->toContain('876543', 'offline-u1-key', str_repeat('0', 32));
})->with(array_keys(U1NotificationVerifier::FIELDS));
