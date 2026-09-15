<?php

use App\Infrastructure\Providers\Blockchain\TronGridBlockchainGateway;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['payment.trongrid_api_key_encrypted' => Crypt::encryptString('test-only-trongrid-key'),
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
    Http::assertSent(fn ($request) => $request->hasHeader('TRON-PRO-API-KEY', 'test-only-trongrid-key'));
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

it('refuses missing encrypted credentials and invalid addresses before HTTP', function (): void {
    config(['payment.trongrid_api_key_encrypted' => 'plaintext-not-allowed']);
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
