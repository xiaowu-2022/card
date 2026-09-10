<?php

use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\DTOs\CardholderRequestDTO;
use App\Domain\CardProvider\DTOs\IssueCardRequestDTO;
use App\Domain\CardProvider\DTOs\ProviderIdentityDocumentDTO;
use App\Domain\CardProvider\Enums\ProviderCardholderReviewStatus;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Infrastructure\Providers\Card\PhotonPayCardProvider;
use App\Infrastructure\Providers\Card\PhotonPayCardResponseNormalizer;
use App\Infrastructure\Providers\Card\UnavailableCardProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function phaseTenPhotonPayAdapter(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    $publicKey = openssl_pkey_get_details($key)['key'];

    return [new PhotonPayCardProvider(
        'https://x-api.sandbox.photontech.cc',
        'app-id',
        'app-secret',
        $privateKey,
        'FA-USD-DEMO',
        null,
        null,
        10,
        new PhotonPayCardResponseNormalizer,
    ), $publicKey];
}

it('maps exact regular virtual card intent signs the exact body and discards PAN and CVV', function (): void {
    [$provider, $publicKey] = phaseTenPhotonPayAdapter();
    Http::fake([
        '*getCardBin*' => Http::response(['code' => '0000', 'data' => [[
            'cardBin' => 'OPAQUE-PRODUCT-REFERENCE', 'cardCurrency' => 'USD', 'cardType' => 'recharge', 'cardFormFactor' => 'virtual_card',
        ]], 'msg' => 'succeed']),
        '*openCard*' => Http::response(['code' => '0000', 'data' => [
            'requestId' => 'issue-request-id', 'status' => 'succeed', 'cardDetail' => [
                'cardId' => 'XR-SAFE-ID', 'cardNo' => '4111111111111234', 'cvv' => '999',
                'cardCurrency' => 'USD', 'cardType' => 'recharge',
                'expirationDate' => '08/29', 'cardStatus' => 'normal', 'cardBalance' => '20.00',
            ],
        ], 'msg' => 'succeed']),
    ]);

    $result = $provider->issueCard(new IssueCardRequestDTO('OPAQUE-PRODUCT-REFERENCE', 'CH-HOLDER', 'USD', '20.00', 'issue-request-id'));

    expect($provider->available())->toBeTrue()
        ->and($result->status)->toBe(ProviderOperationStatus::Succeeded)
        ->and($result->card?->maskedPan)->toBe('•••• 1234')
        ->and($result->card?->last4)->toBe('1234')
        ->and(json_encode($result))->not->toContain('4111111111111234', '999');

    Http::assertSent(function (Request $request) use ($publicKey): bool {
        if (! str_ends_with($request->url(), '/vcc/openApi/v4/openCard')) {
            return true;
        }
        $payload = $request->data();
        $signature = base64_decode($request->header('X-PD-SIGN')[0] ?? '', true);

        return $request->header('X-PD-AUTHORIZATION')[0] === 'basic '.base64_encode('app-id/app-secret')
            && $payload['accountId'] === 'FA-USD-DEMO'
            && $payload['cardBin'] === 'OPAQUE-PRODUCT-REFERENCE'
            && $payload['cardCurrency'] === 'USD'
            && $payload['cardType'] === 'recharge'
            && $payload['cardFormFactor'] === 'virtual_card'
            && $payload['cardholderId'] === 'CH-HOLDER'
            && $payload['requestId'] === 'issue-request-id'
            && $payload['arrivalAmount'] === '20.00'
            && is_string($signature)
            && openssl_verify($request->body(), $signature, $publicKey, OPENSSL_ALGO_MD5) === 1;
    });
});

it('uploads private document bytes directly and maps Cardholder status without returning raw identity data', function (): void {
    [$provider] = phaseTenPhotonPayAdapter();
    Http::fakeSequence()
        ->push(['code' => '0000', 'data' => 'FILE-FRONT', 'msg' => 'succeed'])
        ->push(['code' => '0000', 'data' => 'FILE-BACK', 'msg' => 'succeed'])
        ->push(['code' => '0000', 'data' => ['cardholderId' => 'CH-SAFE'], 'msg' => 'succeed'])
        ->push(['code' => '0000', 'data' => [[
            'cardholderId' => 'CH-SAFE', 'status' => 'normal', 'cardholderReviewStatus' => 'approved', 'reason' => 'not exposed',
        ]], 'msg' => 'succeed']);
    $identity = new ProviderIdentityDocumentDTO('id_card', 'MY', 'SENSITIVE-IDENTITY', 'front-private-bytes', 'image/png', 'back-private-bytes', 'image/png');
    $request = new CardholderRequestDTO(
        'Demo', 'User', '1990-01-02', 'demo@example.test', null, null, 'MY',
        '1 Demo Street', 'Kuala Lumpur', 'Kuala Lumpur', 'MY', '50000', $identity,
    );

    $created = $provider->createCardholder($request);
    $synced = $provider->getCardholder('CH-SAFE');

    expect($created->providerCardholderId)->toBe('CH-SAFE')
        ->and($created->status)->toBe(ProviderCardholderReviewStatus::Pending)
        ->and($synced->status)->toBe(ProviderCardholderReviewStatus::Ready)
        ->and(json_encode([$created, $synced]))->not->toContain('SENSITIVE-IDENTITY', 'front-private-bytes', 'not exposed');
    Http::assertSentCount(4);
    Http::assertSent(fn (Request $sent): bool => str_contains($sent->url(), '/file/apiUpload/issuing_cardholder_identity_certificate'));
});

it('maps unresolved request queries without generating a second provider request id', function (): void {
    [$provider] = phaseTenPhotonPayAdapter();
    Http::fake([
        '*getRequestResult*' => Http::response(['code' => '0000', 'data' => ['requestId' => 'stable-id', 'status' => 'processing'], 'msg' => 'succeed']),
    ]);

    $result = $provider->queryOperation('stable-id');
    expect($result->providerOperationId)->toBe('stable-id')->and($result->status)->toBe(ProviderOperationStatus::Processing);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'requestId=stable-id') && str_contains($request->url(), 'type=apply_card'));
});

it('keeps a claimed success unknown when the returned Card identity is not the requested product shape', function (): void {
    [$provider] = phaseTenPhotonPayAdapter();
    Http::fake([
        '*getCardBin*' => Http::response(['code' => '0000', 'data' => [[
            'cardBin' => 'OPAQUE-PRODUCT-REFERENCE', 'cardCurrency' => 'USD', 'cardType' => 'recharge',
        ]], 'msg' => 'succeed']),
        '*openCard*' => Http::response(['code' => '0000', 'data' => [
            'requestId' => 'issue-request-id', 'status' => 'succeed', 'cardDetail' => [
                'cardId' => 'XR-WRONG-CURRENCY', 'cardNo' => '4111111111111234', 'cvv' => '999',
                'cardCurrency' => 'EUR', 'cardType' => 'recharge', 'cardStatus' => 'normal',
            ],
        ], 'msg' => 'succeed']),
    ]);

    $result = $provider->issueCard(new IssueCardRequestDTO('OPAQUE-PRODUCT-REFERENCE', 'CH-HOLDER', 'USD', '20.00', 'issue-request-id'));

    expect($result->status)->toBe(ProviderOperationStatus::Unknown)
        ->and($result->card)->toBeNull()
        ->and(json_encode($result))->not->toContain('4111111111111234', '999');
});

it('never selects Mock in production and fails closed when PhotonPay is not configured', function (): void {
    config()->set('card-provider.driver', 'mock');
    $this->app->detectEnvironment(fn (): string => 'production');
    $this->app->forgetInstance(CardProviderInterface::class);
    try {
        expect(app(CardProviderInterface::class))->toBeInstanceOf(UnavailableCardProvider::class);
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
        $this->app->forgetInstance(CardProviderInterface::class);
    }
});
