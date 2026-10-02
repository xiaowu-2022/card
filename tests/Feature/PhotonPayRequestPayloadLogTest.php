<?php

use App\Support\Logging\PhotonPayLog;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('encrypts issuing parameters and raw failure responses with span correlation', function (): void {
    $key = random_bytes(32);
    config(['card-provider.photonpay.request_log_key' => 'base64:'.base64_encode($key)]);
    $records = [];
    $logger = Mockery::mock();
    $logger->shouldReceive('info')->twice()->andReturnUsing(function ($event, $context) use (&$records): void {
        $records[] = $context;
    });
    Log::shouldReceive('channel')->with('photonpay_requests')->andReturn($logger);
    Log::shouldReceive('channel')->withAnyArgs()->andReturnSelf();
    Log::shouldReceive('info')->zeroOrMoreTimes();
    PhotonPayLog::run('request', ['endpoint' => '/vcc/openApi/v4/addCardholder'], function ($trace): void {
        $trace->requestPayload(['firstName' => 'Synthetic-private-name', 'certId' => 'synthetic-id']);
        $trace->response(new Response(Http::response('{"code":"VCC1092","data":null,"msg":"synthetic reason"}', 200)->wait()));
    });
    $cipher = new Encrypter($key, 'aes-256-gcm');
    $request = json_decode($cipher->decryptString($records[0]['encrypted_envelope']), true);
    $response = json_decode($cipher->decryptString($records[1]['encrypted_envelope']), true);
    expect(base64_decode($request['body_base64']))->toContain('Synthetic-private-name')
        ->and(base64_decode($response['body_base64']))->toContain('synthetic reason')
        ->and($records[0]['span_id'])->toBe($records[1]['span_id'])
        ->and(json_encode($records))->not->toContain('Synthetic-private-name');
});

it('never captures activation PIN or an activation response that could echo it', function (): void {
    $key = random_bytes(32);
    config(['card-provider.photonpay.request_log_key' => 'base64:'.base64_encode($key)]);
    $records = [];
    $logger = Mockery::mock();
    $logger->shouldReceive('info')->once()->andReturnUsing(function ($event, $context) use (&$records): void {
        $records[] = $context;
    });
    Log::shouldReceive('channel')->with('photonpay_requests')->andReturn($logger);
    Log::shouldReceive('channel')->withAnyArgs()->andReturnSelf();
    Log::shouldReceive('info')->zeroOrMoreTimes();
    PhotonPayLog::run('request', ['endpoint' => '/vcc/openApi/v4/activateCard'], function ($trace): void {
        $trace->requestPayload(['cardId' => 'synthetic-card', 'pin' => '9381', 'pinConfirm' => '9381']);
        $trace->response(new Response(Http::response('{"code":"0000","msg":"9381"}', 200)->wait()));
    });
    expect((new Encrypter($key, 'aes-256-gcm'))->decryptString($records[0]['encrypted_envelope']))->not->toContain('9381');
    $decoded = json_decode((new Encrypter($key, 'aes-256-gcm'))->decryptString($records[0]['encrypted_envelope']), true);
    expect(base64_decode($decoded['body_base64']))->not->toContain('9381')->not->toContain('pin');
});

it('records reporting HTTP query and raw failure response without authorization headers', function (): void {
    $key = random_bytes(32);
    config(['card-provider.photonpay.request_log_key' => 'base64:'.base64_encode($key)]);
    $records = [];
    $logger = Mockery::mock();
    $logger->shouldReceive('info')->twice()->andReturnUsing(function ($event, $context) use (&$records): void {
        $records[] = $context;
    });
    Log::shouldReceive('channel')->with('photonpay_requests')->andReturn($logger);
    Log::shouldReceive('channel')->with('photonpay')->andReturn(Mockery::mock()->shouldIgnoreMissing());
    $body = '{"code":"error","msg":"synthetic rejection","unknown":123.456789012345678}';
    Http::fake(['*' => Http::response($body, 403)]);
    $response = PhotonPayLog::http(Http::withHeaders(['Authorization' => 'synthetic-secret']), 'GET', 'https://example.test/wallet/openApi/v4/account/single', ['memberId' => 'synthetic-member']);
    $cipher = new Encrypter($key, 'aes-256-gcm');
    $request = json_decode($cipher->decryptString($records[0]['encrypted_envelope']), true);
    $result = json_decode($cipher->decryptString($records[1]['encrypted_envelope']), true);
    expect($response->status())->toBe(403)
        ->and(base64_decode($request['body_base64']))->toBe('{"memberId":"synthetic-member"}')
        ->and(base64_decode($result['body_base64']))->toBe($body)
        ->and(json_encode([$request, $result]))->not->toContain('synthetic-secret');
});

it('reads only the requested encrypted payloads through the local diagnostic command', function (): void {
    $key = random_bytes(32);
    config(['card-provider.photonpay.request_log_key' => 'base64:'.base64_encode($key)]);
    $id = (string) \Illuminate\Support\Str::uuid();
    $date = '1900-01-02';
    $path = storage_path('logs/photonpay-requests-'.$date.'.log');
    expect(file_exists($path))->toBeFalse();
    $envelope = (new Encrypter($key, 'aes-256-gcm'))->encryptString(json_encode([
        'context' => ['endpoint' => '/test'], 'direction' => 'response', 'body_base64' => base64_encode('synthetic-result'),
    ]));
    try {
        file_put_contents($path, json_encode(['context' => ['request_id' => $id, 'span_id' => 'synthetic-span', 'encrypted_envelope' => $envelope]])."\n");
        $this->artisan('photonpay:request-log', ['request_id' => $id, '--date' => $date])
            ->expectsOutputToContain('synthetic-result')->assertSuccessful();
        $this->artisan('photonpay:request-log', ['request_id' => (string) \Illuminate\Support\Str::uuid(), '--date' => $date])
            ->expectsOutputToContain('No captured payloads')->assertFailed();
    } finally {
        unlink($path);
    }
});
