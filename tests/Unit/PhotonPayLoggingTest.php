<?php

use App\Domain\CardProvider\Exceptions\ProviderAuthenticationException;
use App\Domain\CardProvider\Exceptions\ProviderRateLimitException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use App\Infrastructure\Providers\Card\PhotonPayCardProvider;
use App\Infrastructure\Providers\Card\PhotonPayCardResponseNormalizer;
use App\Support\Errors\DomainException;
use App\Support\Logging\PhotonPayLog;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

uses(TestCase::class);

it('correlates nested and deferred diagnostics and clears worker context after exceptions', function (): void {
    $eventId = '01a0a9ee-6adc-7277-ab91-312fee636acb';
    $otherId = '01a09996-8c36-7288-bf98-889103088ba6';
    $deferred = null;
    try {
        PhotonPayLog::withContext(['event_id' => $eventId], function () use ($otherId, &$deferred): void {
            PhotonPayLog::run('request', [], fn () => null);
            $deferred = PhotonPayLog::contextCallback(fn () => PhotonPayLog::write('card_refresh.applied'));
            PhotonPayLog::withContext(['event_id' => $otherId], fn () => PhotonPayLog::write('notification.processing'));
            PhotonPayLog::write('notification.retry');
            throw new RuntimeException('never-log-private-exception');
        });
    } catch (RuntimeException) {
        // Long-lived worker continues with an unrelated task.
    }
    $deferred();
    PhotonPayLog::write('notification.processing');
    $records = $this->handler->getRecords();
    expect($records[0]->context['event_id'])->toBe($eventId)
        ->and($records[1]->context['event_id'])->toBe($eventId)
        ->and($records[2]->context['event_id'])->toBe($otherId)
        ->and($records[3]->context['event_id'])->toBe($eventId)
        ->and($records[4]->context['event_id'])->toBe($eventId)
        ->and($records[5]->context)->not->toHaveKey('event_id');
});

it('filters notification stages and exact balance diagnostics at the logging boundary', function (): void {
    PhotonPayLog::write('notification.retry', ['stage' => 'transaction_lookup', 'attempt' => 2,
        'event_status' => 'RETRY', 'queue_driver' => 'redis', 'has_transaction' => true,
        'previous_balance' => '20.00000000', 'provider_balance' => '16.99999999',
        'stored_balance' => 16.99999999, 'transaction_ref' => 'private-transaction',
        'body' => 'private-body', 'reason' => 'private-provider-text']);
    $records = $this->handler->getRecords();
    expect($records[0]->context)->toBe([
        'attempt' => 2, 'has_transaction' => true, 'event_status' => 'RETRY',
        'queue_driver' => 'redis', 'stage' => 'transaction_lookup',
        'previous_balance' => '20.00000000', 'provider_balance' => '16.99999999',
    ]);
    PhotonPayLog::write('card_refresh.failed', ['stage' => 'private-stage', 'event_status' => 'private-status',
        'provider_balance' => '4111111111111111', 'stored_balance' => '1e4',
        'failure' => PhotonPayLog::failure(new DomainException('CARD_REFRESH_SUPERSEDED', 'private-message', 409))]);
    $records = $this->handler->getRecords();
    expect(end($records)->context)->toBe(['failure' => 'refresh_superseded']);
});

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->handler = new TestHandler;
    $handler = $this->handler;
    Log::extend('photonpay_test', fn () => new Logger('photonpay', [$handler]));
    config(['logging.channels.photonpay' => ['driver' => 'photonpay_test']]);
    Log::forgetChannel('photonpay');
});

function diagnosticPhotonProvider(bool $token = false): PhotonPayCardProvider
{
    $private = '';
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), $private);

    return new PhotonPayCardProvider('https://x-api.sandbox.photontech.cc', 'logging-test-app', 'logging-secret-marker', $private, 'account', null, null, 5, new PhotonPayCardResponseNormalizer, $token);
}

it('logs token cache and endpoint metadata without credentials or untrusted response fields', function (): void {
    Http::fake([
        '*accessToken' => Http::response(['code' => '0000', 'data' => ['token' => 'never-log-access-token', 'expiresIn' => (string) ((time() + 3600) * 1000)]]),
        '*getCardBin*' => Http::response(['code' => '0000', 'msg' => 'never-log-provider-message', 'data' => [], 'pan' => '4111111111111111', 'cvv' => 'synthetic-cvv-marker']),
    ]);
    diagnosticPhotonProvider(true)->productAvailable('367218', 'USD');
    diagnosticPhotonProvider(true)->productAvailable('367218', 'USD');
    Http::assertSentCount(3);
    $records = $this->handler->getRecords();
    $text = json_encode($records);
    foreach (['never-log-access-token', 'logging-secret-marker', 'never-log-provider-message', '4111111111111111', 'synthetic-cvv-marker', 'BEGIN PRIVATE KEY'] as $secret) {
        expect(str_contains($text, $secret))->toBeFalse();
    }
    $tokens = array_values(array_filter($records, fn ($r) => $r->message === 'photonpay.token.returned'));
    expect($tokens)->toHaveCount(2)
        ->and($tokens[0]->context['http_status'])->toBe(200)
        ->and($tokens[1]->context['cache_hit'])->toBeTrue();
    $request = array_values(array_filter($records, fn ($r) => $r->message === 'photonpay.request.returned'))[0];
    expect($request->context['endpoint'])->toBe('/vcc/openApi/v4/getCardBin')
        ->and($request->context['provider_code'])->toBe('0000')
        ->and($request->context['duration_ms'])->toBeInt();
});

it('preserves HTTP failure types and excludes provider bodies', function (int $status, string $exception, string $category): void {
    Http::fake(['*' => Http::response(['code' => 'VCC1010', 'msg' => 'hidden-response'], $status)]);
    expect(fn () => diagnosticPhotonProvider()->getCard('CARD-REAL-ONE'))->toThrow($exception);
    $record = array_values(array_filter($this->handler->getRecords(), fn ($r) => $r->message === 'photonpay.request.failed'))[0];
    expect($record->context['http_status'])->toBe($status)
        ->and($record->context['failure'])->toBe($category)
        ->and(str_contains(json_encode($record), 'hidden-response'))->toBeFalse();
    Http::assertSentCount(1);
})->with([
    [401, ProviderAuthenticationException::class, 'authentication'],
    [429, ProviderRateLimitException::class, 'rate_limited'],
    [503, ProviderUnknownResultException::class, 'unknown_result'],
]);

it('keeps transport timeouts unknown without retries or exception text', function (): void {
    Http::fake(['*' => Http::failedConnection('contains-private-request-details')]);
    expect(fn () => diagnosticPhotonProvider()->getCard('CARD-REAL-ONE'))->toThrow(ProviderUnknownResultException::class);
    $records = $this->handler->getRecords();
    expect(end($records)->context['failure'])->toBe('connection')
        ->and(str_contains(json_encode($records), 'contains-private-request-details'))->toBeFalse();
});

it('accepts only diagnostic fields and hashes unknown codes', function (): void {
    PhotonPayLog::write('webhook.received', [
        'body' => 'raw-body-marker', 'signature' => 'raw-signature-marker', 'headers' => ['Authorization' => 'raw-header-marker'],
        'category' => 'forged-category-marker', 'tenant_id' => 'bad-tenant', 'endpoint' => '/secret/4111111111111111',
        'duration_ms' => 2, 'provider_code' => 'unrecognized-provider-code-marker',
    ]);
    PhotonPayLog::run('request', [], function (PhotonPayLog $trace): void {
        $trace->response(new Illuminate\Http\Client\Response(new Response(200, [], '{"code":"unrecognized-provider-code-marker","token":"do-not-log"}')));
    });
    $records = $this->handler->getRecords();
    expect($records[0]->context)->toBe(['duration_ms' => 2]);
    $last = end($records);
    expect($last->context['provider_code_ref'])->toBe(PhotonPayLog::reference('unrecognized-provider-code-marker'));
    foreach (['raw-body-marker', 'raw-signature-marker', 'raw-header-marker', 'forged-category-marker', 'bad-tenant', '4111111111111111', 'unrecognized-provider-code-marker', 'do-not-log'] as $secret) {
        expect(str_contains(json_encode($records), $secret))->toBeFalse();
    }
});

it('never changes returned results or thrown exceptions when logging fails', function (): void {
    Log::shouldReceive('channel')->with('photonpay')->andThrow(new RuntimeException('log disk unavailable'));
    expect(PhotonPayLog::run('request', [], fn () => 'original-result'))->toBe('original-result');
    $failure = new ProviderUnknownResultException('original-failure');
    try {
        PhotonPayLog::run('request', [], fn () => throw $failure);
        $this->fail('Expected the original exception');
    } catch (ProviderUnknownResultException $caught) {
        expect($caught)->toBe($failure);
    }
});

it('records exact management amounts and expiry diagnostics without revealed card fields', function (): void {
    config(['app.timezone' => 'Asia/Shanghai', 'database.connections.pgsql.timezone' => 'Asia/Shanghai']);
    $result = ['state' => 'expired', 'id' => 'b7bdccb7-dc31-41ca-a33b-8a5a669d48fe', 'expiresAt' => '2026-09-15T09:24:58+08:00',
        'amount' => '344.00000000', 'pan' => '4111111111111111', 'cvv' => 'synthetic-cvv'];
    expect(PhotonPayLog::run('management', ['card_action' => 'confirm'], fn () => $result))->toBe($result);
    $records = $this->handler->getRecords();
    $context = end($records)->context;
    expect($context['order_state'])->toBe('expired')
        ->and($context['card_action'])->toBe('confirm')
        ->and($context['deadline_epoch'])->toBe(strtotime($result['expiresAt']))
        ->and($context['app_timezone'])->toBe('Asia/Shanghai')
        ->and($context['db_timezone_config'])->toBe('Asia/Shanghai')
        ->and($context['result_summary'])->toBe(['amount' => '344.00000000', 'state' => 'expired'])
        ->and(str_contains(json_encode($context), '4111111111111111'))->toBeFalse()
        ->and(str_contains(json_encode($context), 'synthetic-cvv'))->toBeFalse();
});

it('logs rejected notification acknowledgements without raw bodies or headers', function (): void {
    config(['card-provider.photonpay.webhook_public_key' => null]);
    $this->call('POST', 'http://callback.example/webhooks/card-provider', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_PD_SIGN' => 'private-signature-marker',
        'HTTP_X_PD_NOTIFICATION_CATAGORY' => 'injected-category-marker',
        'HTTP_X_PD_NOTIFICATION_TYPE' => 'private-type-marker',
    ], '{"cardId":"private-card-id","pan":"4111111111111111"}')
        ->assertStatus(503)->assertExactJson(['roger' => false]);
    $records = $this->handler->getRecords();
    $last = end($records);
    expect($last->message)->toBe('photonpay.webhook.rejected')
        ->and($last->context['failure'])->toBe('verification_unavailable')
        ->and($last->context['request_id'])->toBeString();
    foreach (['private-signature-marker', 'injected-category-marker', 'private-type-marker', 'private-card-id', '4111111111111111'] as $secret) {
        expect(str_contains(json_encode($records), $secret))->toBeFalse();
    }
    Http::assertNothingSent();
});

function diagnosticResponseLog(string $endpoint, string $body): array
{
    $response = new Illuminate\Http\Client\Response(new Response(200, [], $body));
    $returned = PhotonPayLog::run('request', ['endpoint' => '/vcc/openApi/v4/'.$endpoint], fn (PhotonPayLog $trace) => $trace->response($response));
    expect($returned)->toBe($response)->and($returned->body())->toBe($body);
    $records = test()->handler->getRecords();

    return end($records)->context['response_summary'];
}

it('logs exact provider quote and recharge decimals while excluding private fields', function (): void {
    $body = '{"code":"0000","data":{"rechargeAmount":999999999999.12345678,"arrivalAmount":"999999999999.00000000","rechargeFee":0.12345678,"exchangeRate":1,"rechargeCurrency":"USD","status":"succeed","token":"private-token","cardNo":"4111111111111111","cvv":"private-cvv","certificateNo":"private-identity","email":"private@example.test","msg":"private-error-text"}}';
    foreach (['preRecharge', 'recharge'] as $endpoint) {
        $summary = diagnosticResponseLog($endpoint, $body);
        expect($summary)->toBe([
            'rechargeAmount' => '999999999999.12345678', 'arrivalAmount' => '999999999999.00000000',
            'rechargeFee' => '0.12345678', 'exchangeRate' => '1', 'status' => 'succeed',
            'rechargeCurrency' => 'USD', 'data_shape' => 'object',
        ]);
    }
    foreach (['private-token', '4111111111111111', 'private-cvv', 'private-identity', 'private@example.test', 'private-error-text'] as $secret) {
        expect(str_contains(json_encode($this->handler->getRecords()), $secret))->toBeFalse();
    }
});

it('logs direct and nested card balances without copying nested private data', function (): void {
    expect(diagnosticResponseLog('getCardDetail', '{"code":"0000","data":{"cardBalance":"25.10000001","cardCurrency":"USD","cardStatus":"normal","cardId":"private-card-id"}}'))
        ->toBe(['cardBalance' => '25.10000001', 'cardStatus' => 'normal', 'cardCurrency' => 'USD', 'data_shape' => 'object']);
    expect(diagnosticResponseLog('openCard', '{"code":"0000","data":{"cardDetail":{"cardBalance":20.00,"cardStatus":"normal","cardNo":"4111111111111111","cardDetail":{"cardBalance":"999"}}}}'))
        ->toBe(['cardDetail' => ['cardBalance' => '20.00', 'cardStatus' => 'normal'], 'data_shape' => 'object']);
});

it('logs list counts and pagination without any transaction or holder rows', function (): void {
    foreach (['pagingVccTradeOrder', 'pagingVccCardholder'] as $endpoint) {
        expect(diagnosticResponseLog($endpoint, '{"code":"0000","pageIndex":1,"pageSize":20,"total":1,"data":[{"cardNo":"4111111111111111","email":"private@example.test","transactionAmount":"999"}]}'))
            ->toBe(['data_shape' => 'list', 'pageIndex' => '1', 'pageSize' => '20', 'total' => '1', 'record_count' => 1]);
        expect(diagnosticResponseLog($endpoint, '{"code":"0000","data":[]}'))
            ->toBe(['data_shape' => 'list', 'record_count' => 0]);
    }
});

it('omits sensitive endpoint data even when it contains business-like fields', function (): void {
    foreach (['getCvv', 'addCardholder', 'editCardholder', 'unknownEndpoint'] as $endpoint) {
        expect(diagnosticResponseLog($endpoint, '{"code":"0000","data":{"cardBalance":"123","cardStatus":"normal","token":"private-token","cvv":"private-cvv"}}'))
            ->toBe(['data_shape' => 'object']);
    }
    expect(diagnosticResponseLog('getCardDetail', '{"code":"0000","data":"private-object-key"}'))
        ->toBe(['data_shape' => 'scalar']);
});

it('omits malformed or oversized response data without changing the response', function (): void {
    expect(diagnosticResponseLog('getCardDetail', '{"token":"private-token"'))
        ->toBe(['data_shape' => 'invalid_json']);
    expect(diagnosticResponseLog('getCardDetail', str_repeat('x', 2097153)))
        ->toBe(['data_shape' => 'too_large']);
    expect(diagnosticResponseLog('getCardDetail', '{"data":{"cardBalance":"4111111111111111","status":"private-message","cardCurrency":"private-currency"}}'))
        ->toBe(['data_shape' => 'object']);
});

it('revalidates directly supplied summaries and never rounds float money', function (): void {
    PhotonPayLog::write('management.returned', ['result_summary' => [
        'amount' => 1.23456789, 'debit' => '1.234567891', 'arrival' => '1e3', 'balance' => '4111111111111111',
        'fee' => '0.00000000', 'state' => 'completed', 'kind' => 'load',
        'token' => 'private-token', 'orders' => [['pan' => 'private-pan']],
    ], 'endpoint' => '/vcc/openApi/v4/getCvv', 'response_summary' => ['cardBalance' => '123', 'cardDetail' => ['cardBalance' => '456']]]);
    $records = $this->handler->getRecords();
    expect(end($records)->context['result_summary'])->toBe(['fee' => '0.00000000', 'state' => 'completed', 'kind' => 'load'])
        ->and(end($records)->context)->not->toHaveKey('response_summary');
});

it('logs management history counts and refresh balances without private result fields', function (): void {
    PhotonPayLog::run('management', ['card_action' => 'history'], fn () => ['orders' => [['id' => 'private-order', 'amount' => '200']]]);
    $records = $this->handler->getRecords();
    expect(end($records)->context['result_summary'])->toBe(['record_count' => 1]);
    PhotonPayLog::run('management', ['card_action' => 'refresh'], fn () => ['balance' => '20.12345678', 'syncedAt' => '2026-09-15T12:00:00+08:00']);
    $records = $this->handler->getRecords();
    expect(end($records)->context['result_summary'])->toBe(['balance' => '20.12345678']);
});

it('identifies signed callback validation failures without exposing field values', function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 1024]);
    config(['card-provider.photonpay.webhook_public_key' => openssl_pkey_get_details($key)['key']]);
    $body = '{"cardId":"private-card-marker","requestId":{"secret":"private-field-marker"},"pan":"4111111111111111"}';
    openssl_sign($body, $signature, $key, OPENSSL_ALGO_MD5);
    $this->call('POST', 'http://callback.example/webhooks/card-provider', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_PD_SIGN' => base64_encode($signature),
        'HTTP_X_PD_NOTIFICATION_CATAGORY' => 'issuing', 'HTTP_X_PD_NOTIFICATION_TYPE' => 'auth',
    ], $body)->assertStatus(422)->assertExactJson(['roger' => false]);
    $records = $this->handler->getRecords();
    $last = end($records);
    expect($last->context['stage'])->toBe('notification_identifiers')
        ->and($last->context['reason'])->toBe('identifier_invalid')
        ->and($last->context['field'])->toBe('requestId')
        ->and($last->context['field_state'])->toBe('wrong_type')
        ->and($last->context['signature_verified'])->toBeTrue()
        ->and($last->context['notification_ref'])->toBe(PhotonPayLog::reference($body))
        ->and($last->context['notification_fields']['cardId'])->toBe(['state' => 'valid', 'ref' => PhotonPayLog::reference('private-card-marker')])
        ->and($last->context['notification_fields']['requestId'])->toBe(['state' => 'wrong_type'])
        ->and($last->context['notification_fields']['transactionId'])->toBe(['state' => 'missing']);
    foreach (['private-card-marker', 'private-field-marker', '4111111111111111', base64_encode($signature)] as $private) {
        expect(json_encode($records))->not->toContain($private);
    }
    Http::assertNothingSent();
});

it('drops unknown diagnostic fields reasons and states at the final log boundary', function (): void {
    PhotonPayLog::write('webhook.rejected', ['field' => 'private-marker', 'reason' => 'private-marker',
        'field_state' => 'private-marker', 'stage' => 'private-marker', 'signature_verified' => 'private-marker',
        'body_bytes' => 'private-marker', 'raw_body' => 'private-marker',
        'notification_ref' => 'private-marker', 'notification_fields' => [
            'pan' => ['state' => 'valid', 'ref' => 'private-marker'],
            'cardId' => ['state' => 'valid', 'ref' => 'private-marker', 'raw' => 'private-marker'],
            'requestId' => ['state' => 'private-marker'],
        ]]);
    expect(json_encode($this->handler->getRecords()))->not->toContain('private-marker');
});
