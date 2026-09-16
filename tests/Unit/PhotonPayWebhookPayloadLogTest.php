<?php

use App\Support\Logging\PhotonPayWebhookPayloadLog;
use App\Support\Logging\RedactSensitiveLogContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->payloadHandler = new TestHandler;
    $this->diagnosticHandler = new TestHandler;
    $payloadHandler = $this->payloadHandler;
    $diagnosticHandler = $this->diagnosticHandler;
    Log::extend('payload_test', fn () => new Logger('payload', [$payloadHandler]));
    Log::extend('payload_diagnostics', fn () => new Logger('diagnostics', [$diagnosticHandler]));
    config([
        'logging.channels.photonpay_webhooks' => ['driver' => 'payload_test', 'tap' => [RedactSensitiveLogContext::class]],
        'logging.channels.photonpay' => ['driver' => 'payload_diagnostics'],
        'card-provider.photonpay.webhook_log_key' => 'base64:'.base64_encode(str_repeat('k', 32)),
    ]);
    Log::forgetChannel('photonpay_webhooks');
    Log::forgetChannel('photonpay');
    Http::preventStrayRequests();
});

it('retains every byte in authenticated ciphertext and exposes only validated business parameters', function () {
    $body = '{ "cardId":"XR123", "requestId":"", "transactionAmount":10.00000001,"transactionCurrency":"USD","status":"succeed","pan":"4111111111111111","cvv":"private-cvv-marker","cardholder":{"name":"private-name-marker"},"unknown-field":"private-extra-marker","feeDetailJson":{"customFee":0.00000001},"code":"0000" }';
    (new PhotonPayWebhookPayloadLog)->capture($body, 'private-signature-marker', 'issuing', 'auth');
    $record = $this->payloadHandler->getRecords()[0];
    $context = $record->context;
    expect($context['unverified_parameters'])->toContain(
        ['field' => 'cardId', 'value' => 'XR123'], ['field' => 'requestId', 'value' => ''],
        ['field' => 'transactionAmount', 'value' => '10.00000001'], ['field' => 'code', 'value' => '0000'],
    );
    $cipher = new Encrypter(str_repeat('k', 32), 'aes-256-gcm');
    $decrypted = json_decode($cipher->decryptString($context['encrypted_envelope']), true, 512, JSON_THROW_ON_ERROR);
    expect(base64_decode($decrypted['body_base64'], true))->toBe($body)
        ->and(base64_decode($decrypted['signature_base64'], true))->toBe('private-signature-marker');
    foreach (['4111111111111111', 'private-cvv-marker', 'private-name-marker', 'private-extra-marker', 'private-signature-marker'] as $secret) {
        expect(json_encode($context))->not->toContain($secret);
    }
    $tampered = json_decode(base64_decode($context['encrypted_envelope']), true);
    $tampered['value'][0] = $tampered['value'][0] === 'A' ? 'B' : 'A';
    expect(fn () => $cipher->decryptString(base64_encode(json_encode($tampered))))->toThrow(DecryptException::class);
});

it('encrypts malformed or binary bodies and unexpected values instead of dropping their contents', function (string $body) {
    (new PhotonPayWebhookPayloadLog)->capture($body, 'signature', 'issuing', 'auth');
    $context = $this->payloadHandler->getRecords()[0]->context;
    $decrypted = json_decode((new Encrypter(str_repeat('k', 32), 'aes-256-gcm'))->decryptString($context['encrypted_envelope']), true);
    expect(base64_decode($decrypted['body_base64'], true))->toBe($body)
        ->and($context['unverified_parameters'])->toBe([]);
})->with([
    '{broken-json', "\xFF\x00raw-bytes",
    '{"status":"private-marker","transactionCurrency":"private-marker","requestId":"4111111111111111","unknown":{"status":"succeed"}}',
]);

it('does not emit a plaintext fallback without a valid independent key', function (string $key) {
    config(['card-provider.photonpay.webhook_log_key' => $key]);
    (new PhotonPayWebhookPayloadLog)->capture('{"cvv":"private-marker"}', 'private-signature', 'issuing', 'auth');
    expect($this->payloadHandler->getRecords())->toBe([])
        ->and($this->diagnosticHandler->getRecords()[0]->context['reason'])->toBe('log_key_invalid')
        ->and(json_encode($this->diagnosticHandler->getRecords()))->not->toContain('private-marker', 'private-signature');
})->with(['', 'invalid', 'base64:'.base64_encode('short')]);

it('bounds payload sizes without writing oversized data', function () {
    (new PhotonPayWebhookPayloadLog)->capture(str_repeat('x', 2097153), '', 'issuing', 'auth');
    expect($this->payloadHandler->getRecords())->toBe([])
        ->and($this->diagnosticHandler->getRecords()[0]->context['reason'])->toBe('payload_too_large');
});

it('keeps diagnostic write failures out of the business flow', function () {
    Log::shouldReceive('channel')->with('photonpay_webhooks')->andThrow(new RuntimeException('private-error-marker'));
    Log::shouldReceive('channel')->with('photonpay')->andReturn(new Illuminate\Log\Logger(new Logger('diagnostics', [$this->diagnosticHandler])));
    (new PhotonPayWebhookPayloadLog)->capture('{}', '', 'issuing', 'auth');
    expect($this->diagnosticHandler->getRecords()[0]->context['reason'])->toBe('payload_log_failed')
        ->and(json_encode($this->diagnosticHandler->getRecords()))->not->toContain('private-error-marker');
});

it('correlates encrypted callback evidence with a rejected webhook without weakening verification', function () {
    $key = openssl_pkey_new(['private_key_bits' => 1024]);
    config(['card-provider.photonpay.webhook_public_key' => openssl_pkey_get_details($key)['key']]);
    $this->call('POST', 'http://callback.example/webhooks/card-provider', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_PD_SIGN' => 'private-invalid-signature',
        'HTTP_X_PD_NOTIFICATION_CATAGORY' => 'issuing', 'HTTP_X_PD_NOTIFICATION_TYPE' => 'auth',
    ], '{"transactionAmount":"10","cvv":"private-cvv"}')->assertStatus(401);
    $payload = $this->payloadHandler->getRecords()[0]->context;
    $records = $this->diagnosticHandler->getRecords();
    $rejected = end($records)->context;
    expect($payload['request_id'])->toBe($rejected['request_id'])
        ->and($rejected['signature_verified'])->toBeFalse();
    Http::assertNothingSent();
});

it('writes recoverable JSON lines through the real private rotating channel', function () {
    $directory = sys_get_temp_dir().'/photonpay-log-test-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    config(['logging.channels.photonpay_webhooks' => [
        'driver' => 'daily', 'path' => $directory.'/callback.log', 'level' => 'info', 'max_files' => 7,
        'permission' => 0600, 'locking' => true, 'formatter' => JsonFormatter::class,
        'tap' => [RedactSensitiveLogContext::class],
    ]]);
    Log::forgetChannel('photonpay_webhooks');
    try {
        $body = '{"transactionAmount":10.12345678,"pan":"4111111111111111"}';
        (new PhotonPayWebhookPayloadLog)->capture($body, 'synthetic-signature', 'issuing', 'auth');
        $files = glob($directory.'/*.log');
        expect($files)->toHaveCount(1);
        $bytes = file_get_contents($files[0]);
        $record = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        expect(fileperms($files[0]) & 0777)->toBe(0600)
            ->and($bytes)->not->toContain('4111111111111111', 'synthetic-signature');
        $decrypted = json_decode((new Encrypter(str_repeat('k', 32), 'aes-256-gcm'))->decryptString($record['context']['encrypted_envelope']), true);
        expect(base64_decode($decrypted['body_base64'], true))->toBe($body);
    } finally {
        Log::forgetChannel('photonpay_webhooks');
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
