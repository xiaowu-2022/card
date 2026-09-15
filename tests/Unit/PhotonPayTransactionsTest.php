<?php

use App\Domain\CardProvider\Exceptions\ProviderUnavailableException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use App\Infrastructure\Providers\Card\PhotonPayCardProvider;
use App\Infrastructure\Providers\Card\PhotonPayCardResponseNormalizer;
use App\Infrastructure\Providers\Card\PhotonPayTransactionNormalizer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function photonTransactionRow(array $overrides = []): array
{
    return array_replace([
        'cardId' => 'XR-OWNED', 'cardType' => 'recharge', 'cardCurrency' => 'USD', 'cardFormFactor' => 'virtual_card',
        'transactionId' => 'IT-OWNED', 'transactionAmount' => '123456789012.12345678', 'transactionCurrency' => 'EUR',
        'transactionType' => 'auth', 'status' => 'succeed', 'txnDate' => '2026-09-11T09:05:04',
        'createdAt' => '2026-09-11T10:05:04', 'merchantNameLocation' => 'Shop "00123" \\ example',
        'cardNo' => '4111111111111234', 'cvv' => '123', 'msg' => 'private-provider-error',
    ], $overrides);
}

function photonTransactionBody(array $rows, int $page = 1, int $size = 20, ?int $total = null): string
{
    return json_encode(['code' => '0000', 'data' => $rows, 'pageIndex' => $page, 'pageSize' => $size, 'total' => $total ?? count($rows)], JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $this->transactionProvider = new PhotonPayCardProvider('https://x-api.sandbox.photontech.cc', 'test-app', 'test-secret', $private, 'FA-TEST-USD', 'MEMBER-TEST', 'MATRIX-TEST', 5, new PhotonPayCardResponseNormalizer);
});

it('reads an exact card-filtered real endpoint page and preserves JSON decimal tokens without float conversion', function (): void {
    $body = str_replace('"123456789012.12345678"', '123456789012.12345678', photonTransactionBody([photonTransactionRow()]));
    Http::fake(['*pagingVccTradeOrder*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);
    $page = $this->transactionProvider->getTransactionPage('XR-OWNED', 1, 20);
    expect($page->page)->toBe(1)->and($page->hasMore)->toBeFalse()->and($page->items)->toHaveCount(1);
    $item = $page->items[0];
    expect($item->amount)->toBe('123456789012.12345678')->and($item->currency)->toBe('EUR')
        ->and($item->occurredAt)->toBe('2026-09-11T09:05:04')->and($item->type)->toBe('purchase')
        ->and($item->state)->toBe('completed')->and($item->merchant)->toBe('Shop "00123" \\ example')
        ->and(json_encode($page))->not->toContain('4111111111111234', 'cvv', 'private-provider-error', 'MEMBER-TEST');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request['cardId'] === 'XR-OWNED' && $request['cardType'] === 'recharge' && $request['cardFormFactor'] === 'virtual_card'
        && $request['pageIndex'] === 1 && $request['pageSize'] === 20 && $request['memberId'] === 'MEMBER-TEST'
        && $request['matrixAccount'] === 'MATRIX-TEST' && ! $request->hasHeader('X-PD-SIGN'));
    Http::assertSentCount(1);
});

it('keeps explicit pagination and distinguishes a confirmed empty page', function (): void {
    Http::fakeSequence()->push(photonTransactionBody([photonTransactionRow()], 1, 1, 2))
        ->push(photonTransactionBody([photonTransactionRow(['transactionId' => 'IT-2'])], 2, 1, 2))
        ->push(photonTransactionBody([], 3, 1, 2));
    expect($this->transactionProvider->getTransactionPage('XR-OWNED', 1, 1)->hasMore)->toBeTrue()
        ->and($this->transactionProvider->getTransactionPage('XR-OWNED', 2, 1)->hasMore)->toBeFalse()
        ->and($this->transactionProvider->getTransactionPage('XR-OWNED', 3, 1)->items)->toBe([]);
});

it('fails closed for foreign ownership malformed amounts dates and page metadata', function (array $overrides): void {
    Http::fake(['*' => Http::response(photonTransactionBody([photonTransactionRow($overrides)]))]);
    expect(fn () => $this->transactionProvider->getTransactionPage('XR-OWNED', 1, 20))->toThrow(ProviderUnknownResultException::class);
})->with([
    [['cardId' => 'XR-FOREIGN']], [['cardCurrency' => 'EUR']], [['cardType' => 'share']], [['cardFormFactor' => 'physical_card']],
    [['transactionAmount' => '0.000000001']], [['transactionAmount' => '1000000000000']],
    [['transactionAmount' => 'NaN']], [['transactionCurrency' => 'unsafe']], [['txnDate' => '2026-02-30T00:00:00']],
]);

it('never accepts incomplete pagination or hides duplicate rows as empty success', function (): void {
    Http::fakeSequence()->push(['code' => '0000', 'data' => []])
        ->push(photonTransactionBody([photonTransactionRow()], 2))
        ->push(photonTransactionBody([photonTransactionRow(), photonTransactionRow()]));
    for ($i = 0; $i < 3; $i++) {
        expect(fn () => $this->transactionProvider->getTransactionPage('XR-OWNED', 1, 20))->toThrow(ProviderUnknownResultException::class);
    }
});

it('keeps timeout unconfirmed and blocks legacy test references before HTTP', function (): void {
    Http::fake(['*' => Http::failedConnection()]);
    expect(fn () => $this->transactionProvider->getTransactionPage('XR-OWNED', 1, 20))->toThrow(ProviderUnknownResultException::class);
    Http::fake();
    foreach (['MOCK-CARD-1', 'TEST-CARD-1', 'DEMO-CARD-1'] as $id) {
        expect(fn () => $this->transactionProvider->getTransactionPage($id, 1, 20))->toThrow(ProviderUnavailableException::class);
    }
    Http::assertNothingSent();
});

it('decodes only valid JSON and retains escaped strings and small exact amounts', function (): void {
    $normalizer = new PhotonPayTransactionNormalizer;
    $decoded = $normalizer->decode('{"amount":0.00000001,"text":"0.12 \\"quote\\"","nested":[1,-1e-8]}');
    expect($decoded['amount'])->toBe('0.00000001')->and($decoded['text'])->toBe('0.12 "quote"')
        ->and($decoded['nested'])->toBe(['1', '-1e-8']);
    foreach (['{1:2}', '{"amount":01}', 'NaN', '<html>error</html>'] as $invalid) {
        expect(fn () => $normalizer->decode($invalid))->toThrow(ProviderUnknownResultException::class);
    }
});

it('uses verified card-detail currency only when the trade row omits it and still rejects conflicts', function (): void {
    $normalizer = new PhotonPayTransactionNormalizer;
    $row = photonTransactionRow();
    unset($row['cardCurrency']);
    $body = $normalizer->decode(photonTransactionBody([$row]));
    expect(fn () => $normalizer->page($body, 'XR-OWNED', 1, 20))->toThrow(ProviderUnknownResultException::class);
    expect($normalizer->page($body, 'XR-OWNED', 1, 20, 'USD')->items)->toHaveCount(1);
    $body['data'][0]['cardCurrency'] = 'EUR';
    expect(fn () => $normalizer->page($body, 'XR-OWNED', 1, 20, 'USD'))->toThrow(ProviderUnknownResultException::class);
    $body['data'][0]['cardCurrency'] = 'USD';
    $body['data'][0]['cardId'] = 'XR-OTHER';
    expect(fn () => $normalizer->page($body, 'XR-OWNED', 1, 20, 'USD'))->toThrow(ProviderUnknownResultException::class);
});
