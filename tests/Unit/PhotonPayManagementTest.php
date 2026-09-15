<?php

use App\Domain\CardProvider\DTOs\CardholderUpdateDTO;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use App\Infrastructure\Providers\Card\PhotonPayCardProvider;
use App\Infrastructure\Providers\Card\PhotonPayCardResponseNormalizer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function managementAdapter(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);

    return [new PhotonPayCardProvider('https://x-api.photonpay.com', 'test-app', 'test-secret', $private, 'USD-ACCOUNT', null, null, 10, new PhotonPayCardResponseNormalizer), openssl_pkey_get_details($key)['key']];
}

it('uses the two-step reload API exact decimal quotation and a signed confirmation', function () {
    [$provider,$public] = managementAdapter();
    Http::preventStrayRequests();
    Http::fake([
        '*preRecharge*' => Http::response('{"code":"0000","data":{"requestId":"stable-id","accountId":"USD-ACCOUNT","rechargeCurrency":"USD","arrivalAmountCurrency":"USD","rechargeFeeCurrency":"USD","exchangeRate":1,"rechargeAmount":20.10000001,"arrivalAmount":20,"rechargeFee":0.10000001}}'),
        '*recharge' => Http::response('{"code":"0000","data":{"cardId":"XR-1","status":"succeed","transactionId":"TX-1","exchangeRate":1,"rechargeCurrency":"USD","arrivalAmountCurrency":"USD","rechargeFeeCurrency":"USD","rechargeAmount":20.10000001,"arrivalAmount":20,"rechargeFee":0.10000001}}'),
    ]);
    $quote = $provider->quoteCardLoad('XR-1', '20.00000000', 'stable-id');
    expect($quote->feeAmount)->toBe('0.10000001')->and($quote->debitAmount)->toBe('20.10000001');
    $result = $provider->confirmCardLoad('XR-1', 'stable-id');
    expect($result->status)->toBe(ProviderOperationStatus::Succeeded)->and($result->arrivalAmount)->toBe('20.00000000');
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->data() === ['requestId' => 'stable-id']
        && openssl_verify($request->body(), base64_decode($request->header('X-PD-SIGN')[0]), $public, OPENSSL_ALGO_MD5) === 1);
});

it('rejects mismatched currency quote sums and card identity', function ($mutation) {
    [$provider] = managementAdapter();
    $data = ['cardId' => 'XR-1', 'status' => 'succeed', 'transactionId' => 'TX-1', 'exchangeRate' => '1',
        'rechargeCurrency' => 'USD', 'arrivalAmountCurrency' => 'USD', 'rechargeFeeCurrency' => 'USD',
        'rechargeAmount' => '21', 'arrivalAmount' => '20', 'rechargeFee' => '1'];
    Http::fake(['*' => Http::response(['code' => '0000', 'data' => array_replace($data, $mutation)])]);
    expect(fn () => $provider->confirmCardLoad('XR-1', 'stable-id'))->toThrow(ProviderUnknownResultException::class);
})->with([[['cardId' => 'XR-OTHER']], [['rechargeFeeCurrency' => 'EUR']], [['rechargeAmount' => '22']], [['exchangeRate' => '0.99']]]);

it('confirms net return only from matching card and exact gross to net fee sum', function () {
    [$provider] = managementAdapter();
    Http::fake(['*rechargeReturn' => Http::response(['code' => '0000', 'data' => ['cardId' => 'XR-1', 'status' => 'succeed', 'transactionId' => 'TX-RETURN', 'arrivalAmount' => '9', 'returnFeeAmount' => '1']])]);
    $result = $provider->returnCardFunds('XR-1', '10.00000000', 'return-id');
    expect($result->arrivalAmount)->toBe('9.00000000')->and($result->feeAmount)->toBe('1.00000000');
    Http::assertSent(fn ($request) => $request->data() === ['requestId' => 'return-id', 'cardId' => 'XR-1', 'returnAmount' => '10.00']);
});

it('does not interpret a server error or empty response as a failed money transfer', function () {
    [$provider] = managementAdapter();
    Http::fake(['*' => Http::response(['code' => 'VCC9999', 'msg' => 'PRIVATE PROVIDER DETAILS'], 500)]);
    expect(fn () => $provider->confirmCardLoad('XR-1', 'stable-id'))->toThrow(ProviderUnknownResultException::class, 'Card operation result could not be verified.');
    Http::assertSentCount(1);
});

it('uses only documented freeze cancel and holder edit payloads', function () {
    [$provider] = managementAdapter();
    Http::fake(['*' => Http::response(['code' => '0000'])]);
    $provider->freezeCard('XR-1', 'freeze-id');
    $provider->unfreezeCard('XR-1', 'unfreeze-id');
    $provider->cancelCard('XR-1', 'local-cancel-id');
    $provider->editCardholderFields(new CardholderUpdateDTO('CH-1', ['email' => 'new@example.test']));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/cancelCard') && $request->data() === ['cardId' => 'XR-1']);
    Http::assertSent(fn ($request) => ($request->data()['status'] ?? null) === 'unfreeze' && ($request->data()['requestId'] ?? null) === 'unfreeze-id');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/editCardholder') && $request->data() === ['cardholderId' => 'CH-1', 'email' => 'new@example.test']);
    Http::assertSentCount(4);
});

it('queries recharge evidence with the same request and validates returned ownership', function () {
    [$provider] = managementAdapter();
    $row = ['cardId' => 'XR-1', 'cardCurrency' => 'USD', 'cardType' => 'recharge', 'cardFormFactor' => 'virtual_card',
        'transactionId' => 'TX-1', 'requestId' => 'stable-id', 'transactionType' => 'recharge', 'status' => 'succeed',
        'txnPrincipalChangeCurrency' => 'USD', 'transactionCurrency' => 'USD', 'txnPrincipalChangeAccount' => 'member',
        'arrivalAccount' => 'card', 'txnPrincipalChangeAmount' => '-21', 'arrivalAmount' => '20'];
    Http::fake(['*' => Http::response(['code' => '0000', 'pageIndex' => 1, 'pageSize' => 20, 'total' => 1, 'data' => [$row]])]);
    $result = $provider->queryCardFunds('XR-1', 'stable-id', 'LOAD');
    expect($result->debitAmount)->toBe('21.00000000')->and($result->feeAmount)->toBe('1.00000000');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'pagingVccTradeOrder') && $request['requestId'] === 'stable-id' && $request['cardId'] === 'XR-1');
});

it('readbacks only the requested holder fields without exposing other provider materials', function () {
    [$provider] = managementAdapter();
    Http::fake(['*' => Http::response(['code' => '0000', 'data' => [['cardholderId' => 'CH-1', 'email' => 'new@example.test', 'certificateNo' => 'PRIVATE-ID']]])]);
    expect($provider->cardholderFieldsMatch(new CardholderUpdateDTO('CH-1', ['email' => 'new@example.test'])))->toBeTrue()
        ->and($provider->cardholderFieldsMatch(new CardholderUpdateDTO('CH-1', ['email' => 'wrong@example.test'])))->toBeFalse();
});
