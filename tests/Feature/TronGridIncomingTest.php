<?php

use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Infrastructure\Providers\Blockchain\TronGridBlockchainGateway;
use App\Support\Errors\DomainException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['payment.trongrid_api_key_encrypted' => 'ignored-legacy-secret',
        'payment.trc20_deposit_address' => TronGridBlockchainGateway::TOKEN,
        'payment.trc20_token_contract' => TronGridBlockchainGateway::TOKEN,
        'payment.trc20_required_confirmations' => 20]);
    $this->hash = str_repeat('a', 64);
    $this->receipt = ['id' => $this->hash, 'blockNumber' => 100, 'blockTimeStamp' => 1789264800000,
        'receipt' => ['result' => 'SUCCESS'], 'log' => [
            ['address' => str_repeat('b', 40), 'topics' => []],
            ['address' => 'a614f803b6fd780986a42c78ec9c7f77e6ded13c', 'topics' => [
                'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                str_repeat('0', 64), str_repeat('0', 24).'a614f803b6fd780986a42c78ec9c7f77e6ded13c',
            ], 'data' => str_pad(dechex(100010000), 64, '0', STR_PAD_LEFT)],
        ]];
    $this->gateway = new TronGridBlockchainGateway;
});

function fakeTronReceipt($test, array $receipt, int $head = 125): void
{
    Http::fake([
        'api.trongrid.io/walletsolidity/gettransactioninfobyid' => Http::response($receipt),
        'api.trongrid.io/walletsolidity/getnowblock' => Http::response(['block_header' => ['raw_data' => ['number' => $head]]]),
    ]);
}

it('decodes official USDT receipt logs with exact money and the actual log index', function (): void {
    fakeTronReceipt($this, $this->receipt);
    $transfers = $this->gateway->lookup($this->hash, TronGridBlockchainGateway::TOKEN);
    expect($transfers)->toHaveCount(1)->and($transfers[0]->amount)->toBe('100.01000000')
        ->and($transfers[0]->transferIndex)->toBe(1)->and($transfers[0]->confirmations)->toBe(26);
    Http::assertSent(fn ($request) => ! $request->hasHeader('TRON-PRO-API-KEY') && ! $request->hasHeader('Authorization'));
});

it('does not confirm missing malformed failed or mismatched receipts', function (string $case): void {
    $receipt = $this->receipt;
    match ($case) {
        'missing' => $receipt = [],
        'wrong_id' => $receipt['id'] = str_repeat('b', 64),
        'failed' => $receipt['receipt']['result'] = 'REVERT',
        'missing_result' => $receipt['receipt'] = [],
        'bad_topic' => $receipt['log'][1]['topics'][2] = 'not-hex',
        'bad_amount' => $receipt['log'][1]['data'] = '100.01',
        'bad_height' => $receipt['blockNumber'] = 200,
    };
    fakeTronReceipt($this, $receipt);
    expect(fn () => $this->gateway->lookup($this->hash, TronGridBlockchainGateway::TOKEN))->toThrow(DomainException::class);
})->with(['missing', 'wrong_id', 'failed', 'missing_result', 'bad_topic', 'bad_amount', 'bad_height']);

it('ignores other token contracts and destinations', function (string $case): void {
    $receipt = $this->receipt;
    if ($case === 'token') {
        $receipt['log'][1]['address'] = str_repeat('b', 40);
    } else {
        $receipt['log'][1]['topics'][2] = str_repeat('0', 64);
    }
    fakeTronReceipt($this, $receipt);
    expect($this->gateway->lookup($this->hash, TronGridBlockchainGateway::TOKEN))->toBe([]);
})->with(['token', 'destination']);

it('fails closed on HTTP limits redirects and timeouts without exposing the key', function (int $status): void {
    Http::fake(['*' => Http::response('upstream-test-only-trongrid-key', $status, ['Location' => 'http://127.0.0.1/private'])]);
    try {
        $this->gateway->lookup($this->hash, TronGridBlockchainGateway::TOKEN);
        $this->fail('Expected unavailable');
    } catch (DomainException $e) {
        expect($e->getMessage())->not->toContain('test-only-trongrid-key')->and($e->httpStatus)->toBe(503);
    }
    Http::assertSentCount(1);
})->with([302, 429, 500]);

it('refuses invalid receiving addresses before HTTP', function (): void {
    config(['payment.trc20_deposit_address' => 'invalid-address']);
    expect($this->gateway->available())->toBeFalse();
    expect(fn () => $this->gateway->lookup($this->hash, TronGridBlockchainGateway::TOKEN))->toThrow(DomainException::class);
    Http::assertNothingSent();
});

it('paginates discovery but obtains all credit facts from receipts instead of indexer amounts', function (): void {
    Http::fake([
        'api.trongrid.io/v1/accounts/*' => Http::sequence()
            ->push(['success' => true, 'data' => [['transaction_id' => $this->hash, 'value' => '999999999']], 'meta' => ['fingerprint' => 'second']])
            ->push(['success' => true, 'data' => [['transaction_id' => $this->hash]], 'meta' => []]),
        'api.trongrid.io/walletsolidity/gettransactioninfobyid' => Http::response($this->receipt),
        'api.trongrid.io/walletsolidity/getnowblock' => Http::response(['block_header' => ['raw_data' => ['number' => 125]]]),
    ]);
    $time = (new DateTimeImmutable('@1789264800'));
    $transfers = $this->gateway->between(TronGridBlockchainGateway::TOKEN, $time->modify('-1 minute'), $time->modify('+1 minute'));
    expect($transfers)->toHaveCount(1)->and($transfers[0]->amount)->toBe('100.01000000');
    Http::assertSentCount(4);
});

it('requires complete pagination and never accepts an upstream next URL', function (): void {
    Http::fake(['*' => Http::response(['success' => true, 'data' => [], 'meta' => ['fingerprint' => 'repeat', 'links' => ['next' => 'http://127.0.0.1/private']]])]);
    expect(fn () => $this->gateway->between(TronGridBlockchainGateway::TOKEN, new DateTimeImmutable('-1 minute'), new DateTimeImmutable))->toThrow(DomainException::class);
    Http::assertSentCount(2);
});

it('uses a solid block behind the configured confirmation depth as the scan boundary', function (): void {
    Http::fake([
        'api.trongrid.io/walletsolidity/getnowblock' => Http::response(['block_header' => ['raw_data' => ['number' => 125]]]),
        'api.trongrid.io/walletsolidity/getblockbynum' => Http::response(['block_header' => ['raw_data' => ['number' => 106, 'timestamp' => 1789264800000]]]),
    ]);
    expect($this->gateway->confirmedThrough()->format('U'))->toBe('1789264800');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getblockbynum') && $request['num'] === 106);
});

it('treats transport timeout as unconfirmed without leaking request headers', function (): void {
    Http::fake(['*' => Http::failedConnection()]);
    expect(fn () => $this->gateway->lookup($this->hash, TronGridBlockchainGateway::TOKEN))->toThrow(DomainException::class);
});

it('reads public receipts without any API key configuration', function (): void {
    config(['payment.trongrid_api_key_encrypted' => null]);
    fakeTronReceipt($this, $this->receipt);
    expect($this->gateway->available())->toBeTrue();
    expect($this->gateway->lookup($this->hash, TronGridBlockchainGateway::TOKEN))->toHaveCount(1);
    Http::assertNotSent(fn ($request) => $request->hasHeader('TRON-PRO-API-KEY') || $request->hasHeader('Authorization'));
});

it('uses the public reader in production despite legacy disabled or mock flags', function (string $driver): void {
    $this->app->instance('env', 'production');
    config(['withdrawal.blockchain_driver' => $driver]);
    $this->app->forgetInstance(BlockchainGatewayInterface::class);
    expect(app(BlockchainGatewayInterface::class))->toBeInstanceOf(TronGridBlockchainGateway::class);
})->with(['unavailable', 'mock', 'trongrid']);

it('records only safe request failure metadata for discovery', function (mixed $body, int $status, string $phase): void {
    Log::spy();
    Http::fake(['*' => Http::response($body, $status, ['X-Private' => 'PRIVATE'])]);
    expect(fn () => $this->gateway->between(TronGridBlockchainGateway::TOKEN, new DateTimeImmutable('-1 minute'), new DateTimeImmutable))
        ->toThrow(DomainException::class, 'Blockchain verification is temporarily unavailable.');
    Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) use ($status, $phase): bool {
        expect($message)->toBe('TRC20 public reader request failed')
            ->and(array_keys($context))->toBe(['endpoint', 'phase', 'http_status', 'elapsed_ms', 'transport_errno'])
            ->and($context['endpoint'])->toBe('account_transfers')->and($context['phase'])->toBe($phase)
            ->and($context['http_status'])->toBe($status)->and($context['transport_errno'])->toBeNull()
            ->and($context['elapsed_ms'])->toBeInt()->toBeGreaterThanOrEqual(0)
            ->and(json_encode($context))->not->toContain('PRIVATE', TronGridBlockchainGateway::TOKEN);

        return true;
    });
    Http::assertSentCount(1);
})->with([
    ['PRIVATE throttling response', 429, 'http_status'],
    ['PRIVATE forbidden response', 403, 'http_status'],
    ['PRIVATE server response', 503, 'http_status'],
    ['PRIVATE redirect', 302, 'http_status'],
    ['<html>PRIVATE</html>', 200, 'response_json'],
    ['null', 200, 'response_json'],
    [['Error' => 'PRIVATE'], 200, 'upstream_error'],
    [['error' => 'PRIVATE'], 200, 'upstream_error'],
    [str_repeat('x', 2097153), 200, 'response_size'],
]);

it('records transport errno without recording the exception text or receipt hash', function (): void {
    Log::spy();
    $attempts = 0;
    Http::fake(function () use (&$attempts): never {
        $attempts++;
        $cause = new ConnectException('cURL error 28: PRIVATE', new Request('POST', 'https://example.invalid/private'));
        throw new ConnectionException('PRIVATE', 0, $cause);
    });
    expect(fn () => $this->gateway->lookup($this->hash, TronGridBlockchainGateway::TOKEN))->toThrow(DomainException::class);
    Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context): bool {
        expect($context['endpoint'])->toBe('transaction_receipt')->and($context['phase'])->toBe('transport')
            ->and($context['http_status'])->toBeNull()->and($context['transport_errno'])->toBe(28)
            ->and(json_encode($context))->not->toContain('PRIVATE', $this->hash);

        return true;
    });
    expect($attempts)->toBe(1);
});

it('keeps failed reads closed and sanitized when diagnostic logging fails', function (): void {
    Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('PRIVATE log failure'));
    Http::fake(['*' => Http::response('PRIVATE', 429)]);
    try {
        $this->gateway->lookup($this->hash, TronGridBlockchainGateway::TOKEN);
        $this->fail('Expected closed read');
    } catch (DomainException $error) {
        expect($error->httpStatus)->toBe(503)->and($error->getPrevious())->toBeNull()
            ->and($error->getMessage())->not->toContain('PRIVATE');
    }
    Http::assertSentCount(1);
});
