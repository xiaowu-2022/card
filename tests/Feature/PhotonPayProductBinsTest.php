<?php

use App\Application\CardProduct\ArchiveCardProductsAction;
use App\Application\CardProduct\CreateCardProductAction;
use App\Application\CardProduct\RefreshCardFormFactors;
use App\Application\CardProduct\UpdateCardProductAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Infrastructure\Providers\Card\PhotonPayMerchantReport;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seed();
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->owner = AdminUser::query()->where('email', 'owner@platform.local')->firstOrFail();
    [$this->merchant] = photonAccountFixture($this->owner);
    $this->connection = $this->merchant->photonpay_reporting_encrypted;
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
    expect(app(PhotonPayMerchantReport::class)->bins($this->connection))->toBe([['bin' => '522105', 'scheme' => 'MasterCard', 'formFactors' => ['virtual_card', 'physical_card']]]);
    $entries = LedgerEntry::query()->count();
    $product = app(CreateCardProductAction::class)->execute($this->payload, $this->owner);
    expect($product->provider_product_ref)->toBe('522105')->and($product->provider)->toBe('UNCONFIGURED')->and(LedgerEntry::query()->count())->toBe($entries);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'getCardBin') && $request['cardCurrency'] === 'USD' && $request['cardType'] === 'recharge' && $request->hasHeader('X-PD-TOKEN', 'test-bin-token'));
    $this->actingAs($this->owner, 'platform_admin')->get('http://admin.localhost/platform/card-products')
        ->assertOk()->assertInertia(fn ($page) => $page->where('cardProviders.0.bins', [['bin' => '522105', 'scheme' => 'MasterCard', 'formFactors' => ['virtual_card', 'physical_card']]])
        ->where('cardProviders.0.usedBins', fn ($rows) => collect($rows)->contains(fn ($row) => $row['bin'] === '522105' && $row['productId'] === $product->id))->missing('cardProviders.0.photonpay_reporting_encrypted'));
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

it('uses only the saved verified catalog without upstream calls when saving', function (): void {
    $product = app(CreateCardProductAction::class)->execute($this->payload, $this->owner);
    expect($product->provider_product_ref)->toBe('522105');
    Http::assertNothingSent();
});

it('releases archived bins while preserving history and rejects active database duplicates', function (): void {
    $original = app(CreateCardProductAction::class)->execute($this->payload, $this->owner);
    app(ArchiveCardProductsAction::class)->execute([$original->id], $this->owner);
    $historical = $original->fresh()->getAttributes();
    $entries = LedgerEntry::count();
    expect(DB::table('card_bin_claims')->where('bin', '522105')->exists())->toBeFalse();
    $replacement = app(CreateCardProductAction::class)->execute($this->payload, $this->owner);
    expect(fn () => DB::transaction(function () use ($replacement): void {
        $replacement->replicate()->save();
    }))->toThrow(QueryException::class);
    expect($original->fresh()->getAttributes())->toBe($historical)->and(LedgerEntry::count())->toBe($entries);
    expect(DB::table('card_bin_claims')->where('bin', '522105')->value('card_product_id'))->toBe($replacement->id);
});

it('allows unchanged metadata but refuses a changed bin no longer returned by the provider', function (): void {
    photonBinFake($this->rows);
    $product = app(CreateCardProductAction::class)->execute($this->payload, $this->owner);
    $updated = app(UpdateCardProductAction::class)->execute($product->id, [...$this->payload, 'name' => 'Renamed'], $this->owner);
    expect($updated->name)->toBe('Renamed');
    expect(fn () => app(UpdateCardProductAction::class)->execute($product->id, [...$this->payload, 'provider_product_ref' => 'MADEUP'], $this->owner))->toThrow(ValidationException::class);
});

it('persists explicit physical capability refresh and keeps product reads offline', function (): void {
    $rows = $this->rows;
    foreach ($rows as &$row) {
        $row['cardFormFactor'] = 'virtual_card,physical_card';
    } unset($row);
    photonBinFake($rows);
    $product = app(CreateCardProductAction::class)->execute($this->payload, $this->owner);
    app(RefreshCardFormFactors::class)->execute($product->id, $this->owner);
    expect($product->fresh()->supported_form_factors)->toBe(['virtual_card', 'physical_card']);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    $this->actingAs($this->owner, 'platform_admin')->get('http://admin.localhost/platform/card-products')->assertOk();
    Http::assertNothingSent();
});
