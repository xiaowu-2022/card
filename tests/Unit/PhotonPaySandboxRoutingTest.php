<?php

use App\Application\Card\CardProductProviderRouter;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProvider\DTOs\CardholderUpdateDTO;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Providers\Card\PhotonPayCardProvider;
use App\Infrastructure\Providers\Card\PhotonPayCardResponseNormalizer;
use App\Infrastructure\Providers\Card\UnavailableCardProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('reuses tokens for holder updates and freezes until the 110 minute cache boundary', function (): void {
    $private = '';
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $public = openssl_pkey_get_details($key)['key'];
    Http::preventStrayRequests();
    Http::fake([
        '*accessToken' => Http::sequence()
            ->push(['code' => '0000', 'data' => ['token' => 'management-token-1', 'expiresIn' => (string) ((time() + 7200) * 1000)]])
            ->push(['code' => '0000', 'data' => ['token' => 'management-token-2', 'expiresIn' => (string) ((time() + 14400) * 1000)]]),
        '*editCardholder' => Http::response(['code' => '0000']),
        '*freezeCard' => Http::response(['code' => '0000']),
    ]);
    $start = now();
    try {
        foreach ([0, 109, 110] as $minute) {
            $this->travelTo($start->copy()->addMinutes($minute));
            $provider = new PhotonPayCardProvider('https://x-api.sandbox.photontech.cc', 'management-cache-app', 'management-cache-secret', $private, 'account', null, null, 5, new PhotonPayCardResponseNormalizer, true);
            $provider->editCardholderFields(new CardholderUpdateDTO('CH-1', ['email' => 'updated@example.test']));
            expect($provider->freezeCard('XR-1', 'freeze-'.$minute)->status)->toBe(ProviderOperationStatus::Processing);
            $token = $minute < 110 ? 'management-token-1' : 'management-token-2';
            Http::assertSent(fn ($request) => str_ends_with($request->url(), '/freezeCard')
                && $request->data() === ['cardId' => 'XR-1', 'requestId' => 'freeze-'.$minute, 'status' => 'freeze']
                && $request->hasHeader('X-PD-TOKEN', $token)
                && openssl_verify($request->body(), base64_decode($request->header('X-PD-SIGN')[0]), $public, OPENSSL_ALGO_MD5) === 1);
            Http::assertSentCount($minute === 0 ? 3 : ($minute === 109 ? 5 : 8));
        }
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/editCardholder')
            && $request->data() === ['cardholderId' => 'CH-1', 'email' => 'updated@example.test']
            && $request->hasHeader('X-PD-TOKEN', 'management-token-2')
            && openssl_verify($request->body(), base64_decode($request->header('X-PD-SIGN')[0]), $public, OPENSSL_ALGO_MD5) === 1);
    } finally {
        $this->travelBack();
    }
});

it('routes only explicitly encrypted sandbox connections and rejects another host', function (string $host, bool $allowed): void {
    $merchant = new CardProviderReference;
    $merchant->forceFill(['runtime_driver' => 'UNCONFIGURED', 'photonpay_issuing_encrypted' => Crypt::encryptString(json_encode([
        'base_url' => $host, 'app_id' => 'id', 'app_secret' => 'secret', 'private_key' => 'invalid-test-key', 'account_id' => 'account', 'member_id' => 'member',
    ]))]);
    $product = new CardProduct;
    $product->forceFill(['provider' => 'UNCONFIGURED', 'card_provider_reference_id' => '11111111-1111-4111-8111-111111111111']);
    $product->setRelation('cardProviderReference', $merchant);
    $provider = (new CardProductProviderRouter(new UnavailableCardProvider))->forProduct($product);
    expect($provider)->toBeInstanceOf($allowed ? PhotonPayCardProvider::class : UnavailableCardProvider::class);
    expect($merchant->toArray())->not->toHaveKey('photonpay_issuing_encrypted');
})->with([
    ['https://x-api.sandbox.photontech.cc', true],
    ['https://x-api.photonpay.com', false],
    ['http://localhost', false],
]);

it('shares only an encrypted short-lived token across sandbox provider instances', function (): void {
    $private = '';
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $private);
    Http::preventStrayRequests();
    Http::fake([
        '*accessToken' => Http::response(['code' => '0000', 'data' => ['token' => 'test-private-token', 'expiresIn' => (string) ((time() + 3600) * 1000)]]),
        '*getCardBin*' => Http::response(['code' => '0000', 'data' => []]),
    ]);
    for ($i = 0; $i < 2; $i++) {
        $provider = new PhotonPayCardProvider('https://x-api.sandbox.photontech.cc', 'cache-test-app', 'cache-test-secret', $private, 'account', null, null, 5, new PhotonPayCardResponseNormalizer, true);
        expect($provider->productAvailable('367218', 'USD'))->toBeFalse();
    }
    Http::assertSentCount(3);
    $key = 'photonpay-issuing-token:'.hash('sha256', "https://x-api.sandbox.photontech.cc\0cache-test-app\0cache-test-secret");
    $saved = Cache::store('array')->get($key);
    expect($saved)->not->toContain('test-private-token')->and(Crypt::decryptString($saved))->toBe('test-private-token');
});
