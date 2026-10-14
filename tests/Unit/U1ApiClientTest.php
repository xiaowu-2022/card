<?php

use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use App\Infrastructure\Providers\Card\U1ApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('implements the documented U1 string protocol and field signature order', function (string $endpoint, array $fields, array $order): void {
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($endpoint, $fields, $order) {
        expect($request->url())->toBe(U1ApiClient::V2_BASE_URL.'/api/'.$endpoint)
            ->and($request['sign'])->toBe(md5('CUSTOMER'.implode('', array_map(fn ($name) => $fields[$name], $order)).$request['timestamp'].'offline-key'));
        foreach ($request->data() as $value) {
            expect($value)->toBeString();
        }

        return Http::response(['resultCode' => '0000', 'timestamp' => $request['timestamp'], 'sign' => md5('0000'.$request['timestamp'].'offline-key'), 'data' => ['amount' => '0.12345678']]);
    });
    $data = (new U1ApiClient('CUSTOMER', 'offline-key'))->call($endpoint, $fields);
    expect($data['data']['amount'])->toBe('0.12345678');
    Http::assertSentCount(1);
})->with([
    ['queryaccount', [], []], ['querygroupno', [], []],
    ['vccadd', ['currency' => 'USD', 'cardNo' => 'CARD'], ['currency', 'cardNo']],
    ['vccpin', ['bin' => '123456', 'cardNo' => 'CARD'], ['bin', 'cardNo']],
    ['vccquery', ['cardNo' => 'CARD'], ['cardNo']],
    ['vccopen', ['currency' => 'USD', 'amount' => '20.01', 'groupNo' => 'GROUP'], ['currency', 'amount', 'groupNo']],
    ['vccrechage', ['currency' => 'USD', 'amount' => '20.01', 'cardNo' => 'CARD'], ['currency', 'amount', 'cardNo']],
    ['vccwithdraw', ['amount' => '20.01', 'cardNo' => 'CARD'], ['amount', 'cardNo']],
    ['vccemail', ['cardNo' => 'CARD', 'email' => 'offline@example.test'], ['cardNo', 'email']],
    ['vcctrade', ['cardNo' => 'CARD', 'start' => '0', 'size' => '20'], ['cardNo']],
    ['vccquerylist', [], []], ['vccrechargelist', [], []],
    ['vccquerycvv', ['cardNo' => 'CARD'], ['cardNo']], ['queryapiid', ['apiId' => 'API'], ['apiId']],
    ['querycvv', ['cardNo' => 'CARD'], ['cardNo']], ['queryrequestid', ['requestId' => 'REQUEST'], ['requestId']],
    ['notifytest', ['editType' => 'transaction'], []],
]);

it('never retries ambiguous transport responses or leaks response details', function (int $status, string $code, bool $valid): void {
    Http::fake(fn () => Http::response(['resultCode' => $code, 'timestamp' => '1800000000000', 'sign' => $valid ? md5($code.'1800000000000offline-key') : str_repeat('0', 32), 'message' => 'private-provider-detail'], $status));
    expect(fn () => (new U1ApiClient('CUSTOMER', 'offline-key'))->account())->toThrow($code === '0002' && $valid && $status === 200 ? ProviderRejectedException::class : ProviderUnknownResultException::class);
    Http::assertSentCount(1);
})->with([[500, '0000', true], [302, '0000', true], [200, '0000', false], [200, '9999', true], [200, '0002', true]]);

it('sends supplied durable funding IDs and generates unique IDs for reads', function (): void {
    Http::fake(function ($request) {
        expect($request['requestId'])->toBeString()->not->toBeEmpty();

        return Http::response(['resultCode' => '0003', 'timestamp' => $request['timestamp'], 'sign' => md5('0003'.$request['timestamp'].'offline-key')]);
    });
    $client = new U1ApiClient('CUSTOMER', 'offline-key');
    $client->openCard('USD', '20.00', 'GROUP', requestId: 'OPEN-ID');
    $client->recharge('USD', '20.00', 'CARD', 'LOAD-ID');
    $client->withdraw('20.00', 'CARD', 'RETURN-ID');
    $client->account();
    $client->account();
    $requests = Http::recorded()->map(fn ($pair) => $pair[0]['requestId'])->all();
    expect(array_slice($requests, 0, 3))->toBe(['OPEN-ID', 'LOAD-ID', 'RETURN-ID'])
        ->and(array_unique($requests))->toHaveCount(5);
    Http::assertSentCount(5);
});

it('rejects an untrusted card iframe origin', function (): void {
    Http::fake(fn ($request) => Http::response(['resultCode' => '0000', 'timestamp' => $request['timestamp'], 'sign' => md5('0000'.$request['timestamp'].'offline-key'), 'data' => ['url' => 'https://untrusted.example.test/card']]));
    expect(fn () => (new U1ApiClient('CUSTOMER', 'offline-key'))->cardIframe('CARD'))->toThrow(ProviderUnknownResultException::class);
});

it('keeps legacy result queries on v1 without inventing request IDs', function (): void {
    Http::fake(function ($request) {
        expect($request->url())->toBe(U1ApiClient::BASE_URL.'/api/queryapiid')
            ->and($request->data())->not->toHaveKey('requestId');

        return Http::response(['resultCode' => '0000', 'timestamp' => $request['timestamp'],
            'sign' => md5('0000'.$request['timestamp'].'offline-key')]);
    });
    (new U1ApiClient('CUSTOMER', 'offline-key', v2: false))->operation('LEGACY-API');
    Http::assertSentCount(1);
});

it('verifies OTP with the corrected documented order and rejects the previous order', function (): void {
    $data = ['cardNo' => 'OFFLINE-CARD', 'type' => 'otp', 'otp' => '001234', 'timestamp' => '1791965165254'];
    $verifier = new \App\Infrastructure\Providers\Card\U1NotificationVerifier;
    $data['sign'] = md5('OFFLINE-CARDotp0012341791965165254offline-key');
    expect($verifier->verify(json_encode($data), 'offline-key')['verified'])->toBeTrue();
    $data['sign'] = md5('OFFLINE-CARDotp1791965165254001234offline-key');
    expect($verifier->verify(json_encode($data), 'offline-key')['reason'])->toBe('INVALID_SIGNATURE');
});
