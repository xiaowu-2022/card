<?php

use App\Application\Card\CardProductProviderRouter;
use App\Application\Card\LiveCardReferenceGuard;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Domain\Tenant\Contracts\DomainVerificationService;
use App\Infrastructure\Providers\Card\LocalCardSimulation;
use App\Infrastructure\Providers\Card\LocalMockCardProvider;
use App\Infrastructure\Providers\Card\PhotonPayCardProvider;
use App\Infrastructure\Providers\Card\UnavailableCardProvider;
use App\Infrastructure\Providers\Domain\DnsDomainVerificationService;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['card-provider.driver' => 'directory']);
    app()->detectEnvironment(fn () => 'production');
    app()->forgetInstance(CardProviderInterface::class);
});

afterEach(function (): void {
    Http::assertNothingSent();
    app()->detectEnvironment(fn () => 'testing');
});

function unifiedProduct(string $runtime, ?string $connection = null): CardProduct
{
    $merchant = new CardProviderReference;
    $merchant->forceFill(['id' => 'merchant-fixture', 'runtime_driver' => $runtime, 'photonpay_issuing_encrypted' => $connection]);
    $product = new CardProduct;
    $product->forceFill(['id' => 'product-fixture', 'provider' => 'UNCONFIGURED', 'card_provider_reference_id' => 'merchant-fixture']);
    $product->setRelation('cardProviderReference', $merchant);

    return $product;
}

it('preserves each merchant route in the single deployed site without external requests', function (): void {
    $router = app(CardProductProviderRouter::class);
    expect(app(CardProviderInterface::class))->toBeInstanceOf(PhotonPayCardProvider::class)
        ->and(LocalCardSimulation::active())->toBeTrue();
    expect($router->forProduct(unifiedProduct('LOCAL_MOCK')))->toBeInstanceOf(LocalMockCardProvider::class)
        ->and($router->forProduct(unifiedProduct('UNCONFIGURED')))->toBeInstanceOf(UnavailableCardProvider::class);
    $connection = Crypt::encryptString(json_encode(['base_url' => 'https://x-api.sandbox.photontech.cc', 'app_id' => '', 'app_secret' => '', 'private_key' => '', 'account_id' => '', 'member_id' => null]));
    expect($router->forProduct(unifiedProduct('UNCONFIGURED', $connection)))->toBeInstanceOf(PhotonPayCardProvider::class);
    $legacy = new CardProduct;
    $legacy->forceFill(['provider' => 'PHOTONPAY', 'card_provider_reference_id' => null]);
    expect($router->forProduct($legacy))->toBeInstanceOf(PhotonPayCardProvider::class);
});

it('rejects changing the declared database without actually changing the connection', function (): void {
    $connection = DB::connection();
    $original = $connection->getDatabaseName();
    try {
        $connection->setDatabaseName('different_database');
        expect(LocalCardSimulation::enabled())->toBeFalse();
    } finally {
        $connection->setDatabaseName($original);
    }
});

it('never sends mock identities to live products or real identities to simulated merchants', function (): void {
    $legacy = new CardProduct;
    $legacy->forceFill(['provider' => 'PHOTONPAY', 'card_provider_reference_id' => null]);
    expect(fn () => LiveCardReferenceGuard::forProduct($legacy, 'MOCK-LOCAL-CARD-123'))->toThrow(DomainException::class)
        ->and(fn () => LiveCardReferenceGuard::forProduct(unifiedProduct('LOCAL_MOCK'), 'REAL-CARD-123'))->toThrow(DomainException::class);
    LiveCardReferenceGuard::forProduct($legacy, 'REAL-CARD-123');
    LiveCardReferenceGuard::forProduct(unifiedProduct('LOCAL_MOCK'), 'MOCK-LOCAL-CARD-123');
    expect(fn () => LiveCardReferenceGuard::check('MOCK-LOCAL-HOLDER-123'))->toThrow(DomainException::class);
});

it('keeps fixture operation commands unavailable on the deployed site', function (): void {
    $this->artisan('cards:mock-purchase', ['card' => 'not-a-card', 'amount' => '1', 'request' => 'not-a-request'])->assertFailed();
});

it('uses exact DNS TXT proof rather than a configured local whitelist on the deployed site', function (): void {
    expect(app(DomainVerificationService::class))->toBeInstanceOf(DnsDomainVerificationService::class);
    $token = 'vc-verify-'.str_repeat('a', 32);
    $seen = [];
    $verifier = new DnsDomainVerificationService(function ($host) use (&$seen, $token) {
        $seen[] = $host;

        return [['type' => 'TXT', 'txt' => $token]];
    });
    expect($verifier->verify('cards.example.com', $token))->toBeTrue()
        ->and($seen)->toBe(['_vc-verification.cards.example.com'])
        ->and($verifier->verify('https://cards.example.com', $token))->toBeFalse()
        ->and($verifier->verify('127.0.0.1', $token))->toBeFalse()
        ->and($verifier->verify('cards.example.com', 'vc-verify-invalid'))->toBeFalse();
    expect((new DnsDomainVerificationService(fn () => [['type' => 'TXT', 'txt' => 'wrong']]))->verify('cards.example.com', $token))->toBeFalse()
        ->and((new DnsDomainVerificationService(fn () => false))->verify('cards.example.com', $token))->toBeFalse()
        ->and((new DnsDomainVerificationService(fn () => throw new RuntimeException('DNS failure')))->verify('cards.example.com', $token))->toBeFalse();
});
