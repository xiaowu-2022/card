<?php

use App\Application\CardProduct\ArchiveCardProductsAction;
use App\Application\CardProduct\CreateCardProductAction;
use App\Application\CardProduct\UpdateCardProductAction;
use App\Application\CardProviderDirectory\SaveCardProviderReferenceAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Infrastructure\Providers\Card\PhotonPayMerchantReport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    $this->merchant = app(SaveCardProviderReferenceAction::class)->execute(null, ['name' => 'photonpay', 'reference_balance' => '0', 'request_id' => (string) Str::uuid()], $this->owner);
    $this->connection = Crypt::encryptString(json_encode(['base_url' => 'https://x-api.sandbox.photontech.cc', 'app_id' => 'id', 'app_secret' => 'secret']));
    $this->merchant->forceFill(['photonpay_reporting_encrypted' => $this->connection])->save();
    $this->payload = ['name' => 'API product', 'card_provider_reference_id' => $this->merchant->id, 'provider_product_ref' => '522105', 'minimum_initial_load' => '20', 'opening_fee' => '5.00000000', 'minimum_reload' => '20', 'status' => 'DRAFT'];
    $this->rows = [
        ['cardBin' => '522105', 'cardScheme' => 'MasterCard', 'cardCurrency' => 'USD,EUR', 'cardType' => 'share,recharge', 'cardFormFactor' => 'virtual_card,physical_card'],
        ['cardBin' => 'SHAREONLY', 'cardScheme' => 'Discover', 'cardCurrency' => 'USD', 'cardType' => 'share', 'cardFormFactor' => 'virtual_card'],
        ['cardBin' => 'OTHER', 'cardScheme' => 'MasterCard', 'cardCurrency' => 'EUR', 'cardType' => 'recharge', 'cardFormFactor' => 'virtual_card'],
    ];
});

function photonBinFake(array $rows): void
{
    Http::fake([
        '*/oauth2/token/accessToken' => Http::response(['code' => '0000', 'data' => ['token' => 'test-bin-token', 'expiresIn' => (time() + 1200) * 1000]]),
        '*/vcc/openApi/v4/getCardBin*' => Http::response(['code' => '0000', 'data' => $rows]),
    ]);
}

it('offers only eligible deduplicated provider bins and creates without changing funds or runtime', function (): void {
    photonBinFake([...$this->rows, $this->rows[0]]);
    expect(app(PhotonPayMerchantReport::class)->bins($this->connection))->toBe([['bin' => '522105', 'scheme' => 'MasterCard']]);
    $entries = LedgerEntry::query()->count();
    $product = app(CreateCardProductAction::class)->execute($this->payload, $this->owner);
    expect($product->provider_product_ref)->toBe('522105')->and($product->provider)->toBe('UNCONFIGURED')->and(LedgerEntry::query()->count())->toBe($entries);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'getCardBin') && $request['cardCurrency'] === 'USD' && $request['cardType'] === 'recharge' && $request->hasHeader('X-PD-TOKEN', 'test-bin-token'));
    $this->actingAs($this->owner, 'platform_admin')->get('http://admin.localhost/platform/card-products')
        ->assertOk()->assertInertia(fn ($page) => $page->where('cardProviders.0.bins', [['bin' => '522105', 'scheme' => 'MasterCard']])
        ->where('cardProviders.0.usedBins.0.productId', $product->id)->missing('cardProviders.0.photonpay_reporting_encrypted'));
});

it('rejects handcrafted empty incompatible and duplicate bins at the action boundary', function (): void {
    photonBinFake($this->rows);
    foreach (['', 'MADEUP', 'OTHER', 'SHAREONLY'] as $bin) {
        expect(fn () => app(CreateCardProductAction::class)->execute([...$this->payload, 'provider_product_ref' => $bin], $this->owner))->toThrow(ValidationException::class);
    }
    app(CreateCardProductAction::class)->execute($this->payload, $this->owner);
    expect(fn () => app(CreateCardProductAction::class)->execute($this->payload, $this->owner))->toThrow(ValidationException::class);
    expect(CardProduct::query()->where('card_provider_reference_id', $this->merchant->id)->count())->toBe(1);
});

it('fails closed on API errors without using a cached catalog for saving', function (): void {
    Http::fake([
        '*/oauth2/token/accessToken' => Http::response(['code' => '0000', 'data' => ['token' => 'token', 'expiresIn' => (time() + 1200) * 1000]]),
        '*/getCardBin*' => Http::sequence()->push(['code' => '0000', 'data' => $this->rows])->push(['code' => 'unavailable'], 503),
    ]);
    expect(app(PhotonPayMerchantReport::class)->bins($this->connection))->toBe([['bin' => '522105', 'scheme' => 'MasterCard']]);
    expect(fn () => app(CreateCardProductAction::class)->execute($this->payload, $this->owner))->toThrow(ValidationException::class);
});

it('releases archived bins for new products while keeping live duplicates blocked', function (): void {
    photonBinFake($this->rows);
    $original = app(CreateCardProductAction::class)->execute($this->payload, $this->owner);
    app(ArchiveCardProductsAction::class)->execute([$original->id], $this->owner);
    $historical = $original->fresh()->getAttributes();
    $entries = LedgerEntry::query()->count();
    $this->actingAs($this->owner, 'platform_admin');
    $this->get('http://admin.localhost/platform/card-products')->assertOk()
        ->assertInertia(fn ($page) => $page->where('cardProviders.0.usedBins', []));
    $this->post('http://admin.localhost/platform/card-products', [...$this->payload, 'name' => 'Replacement'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $replacement = CardProduct::query()->where('name', 'Replacement')->sole();
    expect($replacement->id)->not->toBe($original->id)
        ->and($replacement->provider_product_ref)->toBe($original->provider_product_ref)
        ->and($original->fresh()->getAttributes())->toBe($historical)
        ->and(LedgerEntry::query()->count())->toBe($entries);
    $this->post('http://admin.localhost/platform/card-products', $this->payload)
        ->assertSessionHasErrors('provider_product_ref');
    expect(fn () => app(CreateCardProductAction::class)->execute($this->payload, $this->owner))
        ->toThrow(ValidationException::class);
    expect(fn () => DB::transaction(function () use ($replacement): void {
        $duplicate = $replacement->replicate();
        $duplicate->save();
    }))->toThrow(QueryException::class);
    $this->get('http://admin.localhost/platform/card-products')->assertOk()
        ->assertInertia(fn ($page) => $page->where('cardProviders.0.usedBins', [
            ['productId' => $replacement->id, 'bin' => '522105'],
        ]));
});

it('allows unchanged metadata but refuses a changed bin no longer returned by the provider', function (): void {
    photonBinFake($this->rows);
    $product = app(CreateCardProductAction::class)->execute($this->payload, $this->owner);
    $updated = app(UpdateCardProductAction::class)->execute($product->id, [...$this->payload, 'name' => 'Renamed'], $this->owner);
    expect($updated->name)->toBe('Renamed');
    expect(fn () => app(UpdateCardProductAction::class)->execute($product->id, [...$this->payload, 'provider_product_ref' => 'MADEUP'], $this->owner))->toThrow(ValidationException::class);
});
